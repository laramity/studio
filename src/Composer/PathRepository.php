<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * Author: Misha Serenkov
 * Email: mi.serenkov@gmail.com
 * Date: 04.05.2023 19:01
 */

namespace Studio\Composer;


use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use Composer\Util\Git as GitUtil;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

class PathRepository extends \Composer\Repository\PathRepository
{
    /**
     * @var ArrayLoader
     */
    private $loader;

    /**
     * @var VersionGuesser
     */
    private $versionGuesser;

    /**
     * @var string
     */
    private $url;

    /**
     * @var mixed[]
     * @phpstan-var array{url: string, options?: array{symlink?: bool, reference?: string, relative?: bool, versions?: array<string, string>}}
     */
    private $repoConfig;

    /**
     * @var ProcessExecutor
     */
    private $process;

    /**
     * @var array{symlink?: bool, reference?: string, relative?: bool, versions?: array<string, string>}
     */
    private $options;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, ?HttpDownloader $httpDownloader = null, ?EventDispatcher $dispatcher = null, ?ProcessExecutor $process = null)
    {
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $dispatcher, $process);

        $this->process = $process ?? new ProcessExecutor($io);
        $this->loader = new ArrayLoader(null, true);
        $this->url = Platform::expandPath($repoConfig['url']);
        $this->versionGuesser = new VersionGuesser($config, $this->process, new VersionParser());

        $this->options = $repoConfig['options'] ?? [];
        if (!isset($this->options['relative'])) {
            $filesystem = new Filesystem();
            $this->options['relative'] = !$filesystem->isAbsolutePath($this->url);
        }
    }

    /**
     * Initializes path repository.
     *
     * This method will basically read the folder and add the found package.
     */
    protected function initialize(): void
    {
        $this->packages = [];

        $urlMatches = $this->getUrlMatches();

        if (empty($urlMatches)) {
            if (Preg::isMatch('{[*{}]}', $this->url)) {
                $url = $this->url;
                while (Preg::isMatch('{[*{}]}', $url)) {
                    $url = dirname($url);
                }
                // the parent directory before any wildcard exists, so we assume it is correctly configured but simply empty
                if (is_dir($url)) {
                    return;
                }
            }

            throw new \RuntimeException('The `url` supplied for the path (' . $this->url . ') repository does not exist');
        }

        foreach ($urlMatches as $url) {
            $path = realpath($url) . DIRECTORY_SEPARATOR;
            $composerFilePath = $path.'composer.json';

            if (!file_exists($composerFilePath)) {
                continue;
            }

            $json = file_get_contents($composerFilePath);
            $package = JsonFile::parseJson($json, $composerFilePath);
            $package['dist'] = [
                'type' => 'path',
                'url' => $url,
            ];
            $reference = $this->options['reference'] ?? 'auto';
            if ('none' === $reference) {
                $package['dist']['reference'] = null;
            } elseif ('config' === $reference || 'auto' === $reference) {
                $package['dist']['reference'] = sha1($json . serialize($this->options));
            }

            // copy symlink/relative options to transport options
            $package['transport-options'] = array_intersect_key($this->options, ['symlink' => true, 'relative' => true]);
            // use the version provided as option if available
            if (isset($package['name'], $this->options['versions'][$package['name']])) {
                $package['version'] = $this->options['versions'][$package['name']];
            }

            // carry over the root package version if this path repo is in the same git repository as root package
            if (!isset($package['version']) && ($rootVersion = Platform::getEnv('COMPOSER_ROOT_VERSION'))) {
                if (
                    0 === $this->process->execute('git rev-parse HEAD', $ref1, $path)
                    && 0 === $this->process->execute('git rev-parse HEAD', $ref2)
                    && $ref1 === $ref2
                ) {
                    $package['version'] = $rootVersion;
                }
            }

            $output = '';
            if ('auto' === $reference && is_dir($path . DIRECTORY_SEPARATOR . '.git') && 0 === $this->process->execute('git log -n1 --pretty=%H'.GitUtil::getNoShowSignatureFlag($this->process), $output, $path)) {
                $package['dist']['reference'] = trim($output);
            }

            if (!isset($package['version'])) {
                $versionData = $this->versionGuesser->guessVersion($package, $path);
                if (is_array($versionData) && $versionData['pretty_version']) {
                    // if there is a feature branch detected, we add a second packages with the feature branch version
                    if (!empty($versionData['feature_pretty_version'])) {
                        $package['version'] = $versionData['feature_pretty_version'];
                        $this->addPackage($this->loader->load($package));
                    }

                    $package['version'] = $versionData['pretty_version'];
                } else {
                    $package['version'] = 'dev-main';
                }
            }

            try {
                $this->addPackage($this->loader->load($package));
            } catch (\Exception $e) {
                throw new \RuntimeException('Failed loading the package in '.$composerFilePath, 0, $e);
            }
        }
    }

    /**
     * Get a list of all (possibly relative) path names matching given url (supports globbing).
     *
     * @return string[]
     */
    private function getUrlMatches(): array
    {
        $flags = GLOB_MARK | GLOB_ONLYDIR;

        if (defined('GLOB_BRACE')) {
            $flags |= GLOB_BRACE;
        } elseif (strpos($this->url, '{') !== false || strpos($this->url, '}') !== false) {
            throw new \RuntimeException('The operating system does not support GLOB_BRACE which is required for the url '. $this->url);
        }

        // Ensure environment-specific path separators are normalized to URL separators
        return array_map(static function ($val): string {
            return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $val), '/');
        }, glob($this->url, $flags));
    }
}
