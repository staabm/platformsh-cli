<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentPushCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:push')
            ->setAliases(['push'])
            ->setDescription('Push code to an environment')
            ->addArgument('src', InputArgument::OPTIONAL, 'The source ref: a branch name or commit hash', 'HEAD')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates')
            ->addOption('force-with-lease', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates, if the remote-tracking branch is up to date')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do everything except actually send the updates')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'After pushing, do not wait for build or deploy')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate the environment after pushing')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set a new environment parent (only used with --activate)');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Ssh::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->setDefaultRepositoryDir($projectRoot);

        // Validate the src argument.
        $source = $input->getArgument('src');
        if (strpos($source, ':') !== false) {
            $this->stdErr->writeln('Invalid ref: ' . $source);
            return 1;
        }

        // Find the target branch name (the name of the current environment, or
        // the Git branch name).
        if ($this->hasSelectedEnvironment()) {
            $target = $this->getSelectedEnvironment()->id;
        } elseif ($currentBranch = $git->getCurrentBranch()) {
            $target = $currentBranch;
        } else {
            $this->stdErr->writeln('Could not determine target environment name.');
            return 1;
        }

        // Guard against accidental pushing to production.
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if ($target === 'master'
            && !$questionHelper->confirm(
                'Are you sure you want to push to the <comment>master</comment> (production) branch?'
            )) {
            return 1;
        }

        // Determine whether to activate the environment after pushing.
        $project = $this->getSelectedProject();
        $activate = false;
        $targetEnvironment = $this->api()->getEnvironment($target, $project);
        if (!$targetEnvironment || !$targetEnvironment->isActive()) {
            $activate = $input->getOption('activate')
                || $questionHelper->confirm('Activate the environment after pushing?');
        }

        // If activating, determine what the environment's parent should be.
        $parentId = 'master';
        if ($activate) {
            $autoCompleterValues = array_keys($this->api()->getEnvironments($project));
            $parentId = $input->getOption('parent')
                ?: $questionHelper->askInput('Parent environment', $parentId, $autoCompleterValues);
            if (!$parent = $this->api()->getEnvironment($parentId, $project)) {
                $this->stdErr->writeln(sprintf('Parent environment not found: <error>%s</error>', $parentId));
                return 1;
            }
        }

        // Ensure the correct Git remote exists.
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $localProject->ensureGitRemote($projectRoot, $project->getGitUrl());

        // Build the Git command.
        $gitArgs = [
            'push',
            $this->config()->get('detection.git_remote_name'),
            $source . ':' . $target,
        ];
        foreach (['force', 'force-with-lease', 'dry-run'] as $option) {
            if ($input->getOption($option)) {
                $gitArgs[] = '--' . $option;
            }
        }

        // Build the SSH command to use with Git.
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $extraSshOptions = [];
        $env = [];
        if ($input->getOption('no-wait')) {
            $extraSshOptions[] = 'SendEnv PLATFORMSH_PUSH_NO_WAIT';
            $env['PLATFORMSH_PUSH_NO_WAIT'] = '1';
        }
        $git->setSshCommand($ssh->getSshCommand($extraSshOptions));

        // Push.
        $this->stdErr->writeln(sprintf('Pushing <info>%s</info> to the environment <info>%s</info>', $source, $target));
        $success = $git->execute($gitArgs, null, false, false, $env);
        if (!$success) {
            return 1;
        }
        if ($input->getOption('dry-run')) {
            return 0;
        }

        // Clear some caches after pushing.
        $this->api()->clearEnvironmentsCache($project->id);
        if ($this->hasSelectedEnvironment()) {
            try {
                $sshUrl = $this->getSelectedEnvironment()->getSshUrl();
                /** @var \Platformsh\Cli\Service\Relationships $relationships */
                $relationships = $this->getService('relationships');
                $relationships->clearCache($sshUrl);
            } catch (EnvironmentStateException $e) {
                // Ignore environments with a missing SSH URL.
            }
        }

        if (!$activate) {
            return 0;
        }

        // Activate the environment.
        $newEnvironment = $this->api()->getEnvironment($target, $project, true);
        if (!$newEnvironment) {
            $this->stdErr->writeln(sprintf(
                'Could not load new environment: <comment>%s</comment>',
                $target
            ));
            return 0;
        } elseif ($newEnvironment->isActive()) {
            return 0;
        }

        $activities = [];

        // Set the environment's parent just before activation.
        if ($newEnvironment->parent !== $parentId) {
            $this->stdErr->writeln(sprintf(
                'Setting the parent of environment <info>%s</info> to <info>%s</info>',
                $newEnvironment->id,
                $parentId
            ));
            $result = $newEnvironment->update(['parent' => $parentId]);
            $activities = array_merge($activities, $result->getActivities());
        }

        $this->stdErr->writeln(sprintf(
            'Activating environment <info>%s</info>',
            $newEnvironment->id
        ));
        $activities[] = $newEnvironment->activate();

        if (!$input->getOption('no-wait')) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($activities, $project);
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}