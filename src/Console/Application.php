<?php declare(strict_types=1);

namespace Rector\Console;

use Jean85\PrettyVersions;
use Rector\Configuration\Configuration;
use Rector\Console\Output\JsonOutputFormatter;
use Rector\Exception\Configuration\InvalidConfigurationException;
use Rector\Utils\DocumentationGenerator\Command\DumpNodesCommand;
use Rector\Utils\DocumentationGenerator\Command\DumpRectorsCommand;
use Rector\Utils\RectorGenerator\Contract\ContributorCommandInterface;
use RuntimeException;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symplify\PackageBuilder\Console\Command\CommandNaming;

final class Application extends SymfonyApplication
{
    /**
     * @var string
     */
    private const NAME = 'Rector';

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @param Command[] $commands
     */
    public function __construct(Configuration $configuration, array $commands = [])
    {
        parent::__construct(self::NAME, PrettyVersions::getVersion('rector/rector')->getPrettyVersion());

        $commands = $this->filterCommandsByScope($commands);
        $this->addCommands($commands);
        $this->configuration = $configuration;
    }

    /**
     * @required
     */
    public function setDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        parent::setDispatcher($eventDispatcher);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $this->configuration->setConfigFilePathFromInput($input);

        $shouldFollowByNewline = false;

        // switch working dir
        $newWorkDir = $this->getNewWorkingDir($input);
        if ($newWorkDir) {
            $oldWorkingDir = getcwd();
            chdir($newWorkDir);
            $output->isDebug() && $output->writeln('Changed CWD form ' . $oldWorkingDir . ' to ' . getcwd());
        }

        // skip in this case, since generate content must be clear from meta-info
        $dumpCommands = [
            CommandNaming::classToName(DumpRectorsCommand::class),
            CommandNaming::classToName(DumpNodesCommand::class),
        ];
        if (in_array($input->getFirstArgument(), $dumpCommands, true)) {
            return parent::doRun($input, $output);
        }

        if ($this->shouldPrintMetaInformation($input)) {
            $output->writeln($this->getLongVersion());
            $shouldFollowByNewline = true;

            $configPath = $this->configuration->getConfigFilePath();
            if ($configPath) {
                $output->writeln('Config file: ' . realpath($configPath));
                $shouldFollowByNewline = true;
            }
        }

        if ($shouldFollowByNewline) {
            $output->write(PHP_EOL);
        }

        return parent::doRun($input, $output);
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $defaultInputDefinition = parent::getDefaultInputDefinition();

        $this->removeUnusedOptions($defaultInputDefinition);
        $this->addCustomOptions($defaultInputDefinition);

        return $defaultInputDefinition;
    }

    /**
     * @param Command[] $commands
     * @return Command[]
     */
    private function filterCommandsByScope(array $commands): array
    {
        // nothing to filter
        if (file_exists(getcwd() . '/bin/rector')) {
            return $commands;
        }

        $filteredCommands = array_filter($commands, function (Command $command): bool {
            return ! $command instanceof ContributorCommandInterface;
        });

        return array_values($filteredCommands);
    }

    private function removeUnusedOptions(InputDefinition $inputDefinition): void
    {
        $options = $inputDefinition->getOptions();

        unset($options['quiet'], $options['no-interaction']);

        $inputDefinition->setOptions($options);
    }

    private function shouldPrintMetaInformation(InputInterface $input): bool
    {
        $hasNoArguments = $input->getFirstArgument() === null;
        $hasVersionOption = $input->hasParameterOption('--version');

        $hasJsonOutput = (
            $input->getParameterOption('--output-format') === JsonOutputFormatter::NAME ||
            $input->getParameterOption('-o') === JsonOutputFormatter::NAME
        );

        return ! ($hasVersionOption || $hasNoArguments || $hasJsonOutput);
    }

    private function addCustomOptions(InputDefinition $inputDefinition): void
    {
        $inputDefinition->addOption(new InputOption(
            'config',
            'c',
            InputOption::VALUE_REQUIRED,
            'Path to config file',
            $this->getDefaultConfigPath()
        ));

        $inputDefinition->addOption(new InputOption(
            'set',
            's',
            InputOption::VALUE_REQUIRED,
            'Finds config by shortcut name'
        ));

        $inputDefinition->addOption(new InputOption(
            'debug',
            null,
            InputOption::VALUE_NONE,
            'Enable debug verbosity (-vvv)'
        ));

        $inputDefinition->addOption(new InputOption(
            '--working-dir',
            '-d',
            InputOption::VALUE_REQUIRED,
            'If specified, use the given directory as working directory.'
        ));
    }

    private function getDefaultConfigPath(): string
    {
        return getcwd() . '/rector.yaml';
    }

    /**
     * @param  InputInterface    $input
     * @throws RuntimeException
     * @return string
     */
    private function getNewWorkingDir(InputInterface $input): string
    {
        $workingDir = $input->getParameterOption(['--working-dir', '-d']);
        if ($workingDir !== false && ! is_dir($workingDir)) {
            throw new InvalidConfigurationException(
                'Invalid working directory specified, ' . $workingDir . ' does not exist.'
            );
        }

        return (string) $workingDir;
    }
}
