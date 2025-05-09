<?php

declare(strict_types=1);

/**
 * Created by PhpStorm.
 * Author: Misha Serenkov
 * Email: mi.serenkov@gmail.com
 * Date: 13.05.2023 16:54
 */

namespace Studio\Composer;

use Composer\Config;
use Composer\Pcre\Preg;
use Composer\Platform\Version;
use Composer\Semver\VersionParser as SemverVersionParser;
use Composer\Util\Git as GitUtil;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;

class VersionGuesser extends \Composer\Package\Version\VersionGuesser
{
    /**
     * @var ProcessExecutor
     */
    private $process;

    /**
     * @var SemverVersionParser
     */
    private $versionParser;

    public function __construct(Config $config, ProcessExecutor $process, SemverVersionParser $versionParser)
    {
        parent::__construct($config, $process, $versionParser);

        $this->process = $process;
        $this->versionParser = $versionParser;
    }

    public function guessVersion(array $packageConfig, string $path): ?array
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        // bypass version guessing in bash completions as it takes time to create
        // new processes and the root version is usually not that important
        if (Platform::isInputCompletionProcess()) {
            return null;
        }

        $versionData = $this->guessGitTagVersion($packageConfig, $path);
        if (isset($versionData['version']) && !empty($versionData['version'])) {
            return $this->postprocess($versionData);
        }

        return parent::guessVersion($packageConfig, $path);
    }

    private function guessGitTagVersion(array $packageConfig, string $path): ?array
    {
        GitUtil::cleanEnv();

        if (0 === $this->process->execute('git describe --tags --abbrev=0', $output, $path)) {
            try {
                $version = $this->versionParser->normalize(trim($output));

                return ['version' => $version, 'pretty_version' => trim($output)];
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    /**
     * @phpstan-param Version $versionData
     *
     * @phpstan-return Version
     */
    private function postprocess(array $versionData): array
    {
        if (!empty($versionData['feature_version']) && $versionData['feature_version'] === $versionData['version'] && $versionData['feature_pretty_version'] === $versionData['pretty_version']) {
            unset($versionData['feature_version'], $versionData['feature_pretty_version']);
        }

        if ('-dev' === substr($versionData['version'], -4) && Preg::isMatch('{\.9{7}}', $versionData['version'])) {
            $versionData['pretty_version'] = Preg::replace('{(\.9{7})+}', '.x', $versionData['version']);
        }

        if (!empty($versionData['feature_version']) && '-dev' === substr($versionData['feature_version'], -4) && Preg::isMatch('{\.9{7}}', $versionData['feature_version'])) {
            $versionData['feature_pretty_version'] = Preg::replace('{(\.9{7})+}', '.x', $versionData['feature_version']);
        }

        return $versionData;
    }
}
