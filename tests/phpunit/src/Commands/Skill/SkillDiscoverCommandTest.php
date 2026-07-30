<?php

declare(strict_types=1);

namespace Acquia\Cli\Tests\Commands\Skill;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Command\Skill\SkillDiscoverCommand;
use Acquia\Cli\Helpers\LocalMachineHelper;
use Acquia\Cli\Tests\CommandTestBase;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

/**
 * @property \Acquia\Cli\Command\Skill\SkillDiscoverCommand $command
 */
class SkillDiscoverCommandTest extends CommandTestBase
{
    private const INSTALL_COMMAND = [
        'npx',
        '--yes',
        'skills@1.5.19',
        'add',
        'acquia/acquia-skills',
        '--global',
        '--skill',
        '*',
        '--yes',
        '--agent',
        'codex',
        '--agent',
        'claude-code',
    ];

    protected function createCommand(): CommandBase
    {
        return $this->injectCommand(SkillDiscoverCommand::class);
    }

    public function testSkillDiscoverCommandNpxMissing(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $localMachineHelper
            ->commandExists('npx')
            ->willReturn(false)
            ->shouldBeCalled();

        $this->executeCommand(['--agent' => ['codex']]);
        $this->assertEquals(Command::FAILURE, $this->getStatusCode());
        $this->assertStringContainsString('npx', $this->getDisplay());
    }

    public function testSkillDiscoverCommandDryRunDoesNotRequireNodeOrInstall(): void
    {
        $this->mockLocalMachineHelper();

        $this->executeCommand([
            '--agent' => ['codex', 'claude-code'],
            '--dry-run' => true,
        ]);

        $this->assertEquals(Command::SUCCESS, $this->getStatusCode());
        $output = $this->getDisplay();
        $this->assertStringContainsString('.agents/skills', $output);
        $this->assertStringContainsString('.codex', $output);
        $this->assertStringContainsString('.claude', $output);
        $this->assertStringContainsString('No changes were made', $output);
    }

    public function testSkillDiscoverCommandRejectsInteractiveDryRun(): void
    {
        $this->mockLocalMachineHelper();

        $this->executeCommand([
            '--dry-run' => true,
            '--interactive' => true,
        ]);

        $this->assertEquals(Command::FAILURE, $this->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $this->getDisplay());
    }

    public function testSkillDiscoverCommandRejectsUnsupportedNodeVersion(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $localMachineHelper->commandExists('npx')->willReturn(true)->shouldBeCalled();
        $localMachineHelper->commandExists('node')->willReturn(true)->shouldBeCalled();
        $localMachineHelper
            ->execute(['node', '--version'], null, null, false, 10, null, false)
            ->willReturn($this->process(true, 'v16.20.0'))
            ->shouldBeCalled();

        $this->executeCommand(['--agent' => ['codex']]);

        $this->assertEquals(Command::FAILURE, $this->getStatusCode());
        $this->assertStringContainsString('18.0.0', $this->getDisplay());
    }

    public function testSkillDiscoverCommandRejectsProjectOnlyAgentForGlobalInstall(): void
    {
        $this->mockLocalMachineHelper();

        $this->executeCommand(['--agent' => ['promptscript']]);

        $this->assertEquals(Command::FAILURE, $this->getStatusCode());
        $this->assertStringContainsString('project-only', $this->getDisplay());
    }

    public function testSkillDiscoverCommandRejectsInteractiveWithExplicitAgent(): void
    {
        $this->mockLocalMachineHelper();

        $this->executeCommand([
            '--agent' => ['codex'],
            '--interactive' => true,
        ]);

        $this->assertEquals(Command::FAILURE, $this->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $this->getDisplay());
    }

    public function testSkillDiscoverCommandInteractiveSuccess(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->expectSupportedNode($localMachineHelper);
        $localMachineHelper
            ->execute(
                [
                    'npx',
                    '--yes',
                    'skills@1.5.19',
                    'add',
                    'acquia/acquia-skills',
                ],
                null,
                null,
                true,
                600
            )
            ->willReturn($this->process(true))
            ->shouldBeCalled();

        $this->executeCommand(['--interactive' => true]);

        $this->assertEquals(Command::SUCCESS, $this->getStatusCode());
        $output = $this->getDisplay();
        $this->assertStringContainsString('Choose project scope', $output);
        $this->assertStringContainsString('completed', $output);
    }

    public function testSkillDiscoverCommandSuccess(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->expectSupportedNode($localMachineHelper);

        $localMachineHelper
            ->execute(self::INSTALL_COMMAND, null, null, true, 300)
            ->willReturn($this->process(true))
            ->shouldBeCalled();

        foreach (['codex', 'claude-code'] as $agent) {
            $localMachineHelper
                ->execute(
                    [
                        'npx',
                        '--yes',
                        'skills@1.5.19',
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
                )
                ->willReturn($this->process(true, 'application-management'))
                ->shouldBeCalled();
        }

        $this->executeCommand(['--agent' => ['codex', 'claude-code']]);

        $this->assertEquals(Command::SUCCESS, $this->getStatusCode());
        $output = $this->getDisplay();
        $this->assertStringContainsString('codex, claude-code', $output);
        $this->assertStringContainsString('globally', $output);
    }

    public function testSkillDiscoverCommandInstallFails(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->expectSupportedNode($localMachineHelper);

        $localMachineHelper
            ->execute(self::INSTALL_COMMAND, null, null, true, 300)
            ->willReturn($this->process(false, '', 'GitHub unavailable'))
            ->shouldBeCalled();

        $this->executeCommand(['--agent' => ['codex', 'claude-code']]);

        $this->assertEquals(Command::FAILURE, $this->getStatusCode());
        $this->assertStringContainsString('GitHub unavailable', $this->getDisplay());
    }

    public function testSkillDiscoverCommandFailsWhenAgentIsNotLinked(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->expectSupportedNode($localMachineHelper);

        $installCommand = self::INSTALL_COMMAND;
        // This test installs only Codex.
        $installCommand = array_slice($installCommand, 0, -2);
        $localMachineHelper
            ->execute($installCommand, null, null, true, 300)
            ->willReturn($this->process(true))
            ->shouldBeCalled();
        $localMachineHelper
            ->execute(
                [
                    'npx',
                    '--yes',
                    'skills@1.5.19',
                    'list',
                    '--global',
                    '--agent',
                    'codex',
                ],
                null,
                null,
                false,
                60,
                null,
                false
            )
            ->willReturn($this->process(true, 'application-management - Agents: not linked'))
            ->shouldBeCalled();

        $this->executeCommand(['--agent' => ['codex']]);

        $this->assertEquals(Command::FAILURE, $this->getStatusCode());
        $this->assertStringContainsString('could not be verified for', $this->getDisplay());
        $this->assertStringContainsString('codex', $this->getDisplay());
    }

    private function expectSupportedNode(LocalMachineHelper|ObjectProphecy $localMachineHelper): void
    {
        $localMachineHelper->commandExists('npx')->willReturn(true)->shouldBeCalled();
        $localMachineHelper->commandExists('node')->willReturn(true)->shouldBeCalled();
        $localMachineHelper
            ->execute(['node', '--version'], null, null, false, 10, null, false)
            ->willReturn($this->process(true, 'v22.0.0'))
            ->shouldBeCalled();
    }

    private function process(bool $successful, string $output = '', string $errorOutput = ''): Process
    {
        $process = $this->prophet->prophesize(Process::class);
        $process->isSuccessful()->willReturn($successful);
        $process->getOutput()->willReturn($output);
        $process->getErrorOutput()->willReturn($errorOutput);
        return $process->reveal();
    }
}
