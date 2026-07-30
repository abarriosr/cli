<?php

declare(strict_types=1);

namespace Acquia\Cli\Command\Skill;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Helpers\LocalMachineHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'skill:discover',
    description: 'Discover and install Acquia skills for your AI coding agent'
)]
final class SkillDiscoverCommand extends CommandBase
{
    private const SKILLS_CLI_VERSION = '1.5.19';
    private const MINIMUM_NODE_VERSION = '18.0.0';
    private const GLOBAL_SKILLS_DIR = '.agents/skills';

    /**
     * PromptScript is deliberately omitted because it only supports project-scoped skills.
     *
     * @var array<string, string>
     */
    private const AGENT_CONFIG_DIRS = [
        'claude-code' => '.claude',
        'codex' => '.codex',
        'cursor' => '.cursor',
        'github-copilot' => '.copilot',
        'opencode' => '.config/opencode',
    ];

    protected function configure(): void
    {
        $this->addOption(
            'agent',
            'a',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Agent to configure. Repeat for multiple agents. If omitted, supported agents are discovered from user configuration directories.'
        )->addOption(
            'interactive',
            null,
            InputOption::VALUE_NONE,
            'Use the interactive Skills CLI to select agents, skills, and project/global scope. Required for project-only agents such as PromptScript.'
        )->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Display detected agents and planned global installation paths without making changes.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string[] $requestedAgents */
        $requestedAgents = $input->getOption('agent');
        if ($input->getOption('interactive')) {
            if ($requestedAgents) {
                $this->io->error('The --interactive and --agent options cannot be used together.');
                return Command::FAILURE;
            }
            if ($input->getOption('dry-run')) {
                $this->io->error(
                    'The --interactive and --dry-run options cannot be used together because interactive choices are delegated to the Skills CLI.'
                );
                return Command::FAILURE;
            }
            if (!$this->prerequisitesAreAvailable()) {
                return Command::FAILURE;
            }
            return $this->executeInteractiveInstallation();
        }

        $agents = $requestedAgents ?: $this->discoverAgents();
        $unsupportedAgents = array_values(array_diff($agents, array_keys(self::AGENT_CONFIG_DIRS)));
        if ($unsupportedAgents) {
            $this->io->error(sprintf(
                'Unsupported global skill target(s): %s. PromptScript and other project-only agents cannot be configured globally.',
                implode(', ', $unsupportedAgents)
            ));
            return Command::FAILURE;
        }
        $agents = array_values(array_unique($agents));
        if (!$agents) {
            $this->io->error(
                'No supported agents were detected. Re-run with one or more explicit targets, for example: --agent=codex --agent=claude-code.'
            );
            return Command::FAILURE;
        }

        $this->displayInstallationPlan($agents);
        if ($input->getOption('dry-run')) {
            $this->io->success('Dry run completed. No changes were made.');
            return Command::SUCCESS;
        }
        if (!$this->prerequisitesAreAvailable()) {
            return Command::FAILURE;
        }
        $this->io->writeln('Installing Acquia skills (acquia/acquia-skills)...');

        $command = [
            'npx',
            '--yes',
            'skills@' . self::SKILLS_CLI_VERSION,
            'add',
            'acquia/acquia-skills',
            '--global',
            '--skill',
            '*',
            '--yes',
        ];
        foreach ($agents as $agent) {
            $command[] = '--agent';
            $command[] = $agent;
        }

        $process = $this->localMachineHelper->execute(
            $command,
            null,
            null,
            true,
            300
        );

        if (!$process->isSuccessful()) {
            $details = trim($process->getErrorOutput() ?: $process->getOutput());
            $message = 'Failed to install acquia/acquia-skills. Check access to both the npm registry and GitHub.';
            if ($details !== '') {
                $message .= PHP_EOL . $details;
            }
            $this->io->error($message);
            return Command::FAILURE;
        }

        foreach ($agents as $agent) {
            if (!$this->verifyAgentRegistration($agent)) {
                $this->io->error(sprintf(
                    'Skills were downloaded, but global registration could not be verified for %s.',
                    $agent
                ));
                return Command::FAILURE;
            }
        }

        $this->io->success(sprintf(
            'Acquia skills installed globally for: %s. Restart your agents to pick up the new skills.',
            implode(', ', $agents)
        ));
        return Command::SUCCESS;
    }

    private function prerequisitesAreAvailable(): bool
    {
        if (!$this->localMachineHelper->commandExists('npx')) {
            $this->io->error('npx is required to install Acquia skills. Install a supported Node.js/npm version and try again.');
            return false;
        }

        if (!$this->nodeVersionIsSupported()) {
            $this->io->error(sprintf(
                'Node.js %s or later is required by the validated Skills CLI release.',
                self::MINIMUM_NODE_VERSION
            ));
            return false;
        }

        return true;
    }

    private function nodeVersionIsSupported(): bool
    {
        if (!$this->localMachineHelper->commandExists('node')) {
            return false;
        }
        $process = $this->localMachineHelper->execute(
            ['node', '--version'],
            null,
            null,
            false,
            10,
            null,
            false
        );
        if (!$process->isSuccessful()) {
            return false;
        }
        $version = ltrim(trim($process->getOutput()), 'v');
        return version_compare($version, self::MINIMUM_NODE_VERSION, '>=');
    }

    private function executeInteractiveInstallation(): int
    {
        $this->io->note(
            'Interactive mode delegates agent, skill, and scope selection to the Skills CLI. '
            . 'Choose project scope for project-only agents such as PromptScript.'
        );
        $process = $this->localMachineHelper->execute(
            [
                'npx',
                '--yes',
                'skills@' . self::SKILLS_CLI_VERSION,
                'add',
                'acquia/acquia-skills',
            ],
            null,
            null,
            true,
            600
        );
        if (!$process->isSuccessful()) {
            $details = trim($process->getErrorOutput() ?: $process->getOutput());
            $message = 'Interactive Acquia skill installation failed. Check access to both the npm registry and GitHub.';
            if ($details !== '') {
                $message .= PHP_EOL . $details;
            }
            $this->io->error($message);
            return Command::FAILURE;
        }
        $this->io->success('Interactive Acquia skill installation completed. Restart configured agents to load the skills.');
        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function discoverAgents(): array
    {
        $home = LocalMachineHelper::getHomeDir();
        $filesystem = $this->localMachineHelper->getFilesystem();
        $agents = [];
        foreach (self::AGENT_CONFIG_DIRS as $agent => $relativeConfigDir) {
            if ($filesystem->exists(Path::join($home, $relativeConfigDir))) {
                $agents[] = $agent;
            }
        }
        return $agents;
    }

    /**
     * @param string[] $agents
     */
    private function displayInstallationPlan(array $agents): void
    {
        $this->io->section('Acquia skill installation plan');
        $this->io->writeln('Global skill directory: ' . Path::join(
            LocalMachineHelper::getHomeDir(),
            self::GLOBAL_SKILLS_DIR
        ));
        $this->io->writeln('Agent targets and detection directories:');
        $home = LocalMachineHelper::getHomeDir();
        foreach ($agents as $agent) {
            $this->io->writeln(sprintf(
                '  - %s: %s',
                $agent,
                Path::join($home, self::AGENT_CONFIG_DIRS[$agent])
            ));
        }
    }

    private function verifyAgentRegistration(string $agent): bool
    {
        $process = $this->localMachineHelper->execute(
            [
                'npx',
                '--yes',
                'skills@' . self::SKILLS_CLI_VERSION,
                'list',
                '--global',
                '--agent',
                $agent,
            ],
            null,
            null,
            false,
            60,
            null,
            false
        );
        if (!$process->isSuccessful()) {
            return false;
        }
        $output = $process->getOutput() . $process->getErrorOutput();
        return !str_contains(strtolower($output), 'not linked')
            && str_contains($output, 'application-management');
    }
}
