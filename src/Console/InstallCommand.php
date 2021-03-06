<?php

namespace OFFLINE\Bootstrapper\October\Console;

use OFFLINE\Bootstrapper\October\Config\Setup;
use OFFLINE\Bootstrapper\October\Config\Yaml;
use OFFLINE\Bootstrapper\October\Downloader\OctoberCms;
use OFFLINE\Bootstrapper\October\Installer\DeploymentInstaller;
use OFFLINE\Bootstrapper\October\Installer\PluginInstaller;
use OFFLINE\Bootstrapper\October\Installer\ThemeInstaller;
use OFFLINE\Bootstrapper\October\Util\Composer;
use OFFLINE\Bootstrapper\October\Util\Gitignore;
use OFFLINE\Bootstrapper\October\Util\RunsProcess;
use OFFLINE\Bootstrapper\October\Util\UsesTemplate;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\LogicException;

/**
 * Class InstallCommand
 * @package OFFLINE\Bootstrapper\October\Console
 */
class InstallCommand extends Command
{
    use UsesTemplate, RunsProcess;

    /**
     * @var
     */
    public $config;
    /**
     * @var OutputInterface
     */
    protected $output;
    /**
     * @var Gitignore
     */
    protected $gitignore;
    /**
     * @var bool
     */
    protected $firstRun;
    /**
     * @var string
     */
    protected $php;

    /**
     * Configure the command options.
     *
     * @throws InvalidArgumentException
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('install')
            ->setDescription('Install October CMS.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Make the installer behave as if it is run for the first time. Existing files may get overwritten.'
            )
            ->addOption(
                'php',
                null,
                InputOption::VALUE_OPTIONAL,
                'Specify the path to a custom PHP binary',
                'php'
            );
    }

    /**
     * Execute the command.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     *
     * @return mixed
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     * @throws \Symfony\Component\Process\Exception\InvalidArgumentException
     * @throws LogicException
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ( ! class_exists('ZipArchive')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        $force     = $input->getOption('force');
        $this->php = $input->getOption('php') ?: 'php';

        $this->firstRun = ! is_dir(getcwd() . DS . 'bootstrap') || $force;

        $this->output = $output;

        $configFile = getcwd() . DS . 'october.yaml';
        if ( ! file_exists($configFile)) {
            return $output->writeln('<comment>october.yaml not found. Run october init first.</comment>');
        }

        $this->config    = new Yaml($configFile);
        $this->gitignore = new Gitignore($this->getGitignore());

        $output->writeln('<info>Downloading latest October CMS...</info>');
        try {
            (new OctoberCms())->download($force);
        } catch (\LogicException $e) {
            $output->writeln('<comment>' . $e->getMessage() . '</comment>');
        }

        $output->writeln('<info>Installing composer dependencies...</info>');
        (new Composer())->install();
        (new Composer())->addDependency('offline/oc-bootstrapper');

        $output->writeln('<info>Setting up config files...</info>');
        $this->writeConfig($force);

        $this->prepareDatabase();

        $output->writeln('<info>Migrating database...</info>');
        $this->runProcess($this->php . ' artisan october:up', 'Migrations failed!');

        $output->writeln('<info>Installing Theme...</info>');
        try {
            (new ThemeInstaller($this->config, $this->gitignore, $this->output, $this->php))->install();
        } catch (\RuntimeException $e) {
            $output->writeln('<comment>' . $e->getMessage() . '</comment>');
        }

        $output->writeln('<info>Installing Plugins...</info>');
        try {
            (new PluginInstaller($this->config, $this->gitignore, $this->output, $this->php))->install();
        } catch (\RuntimeException $e) {
            $output->writeln('<comment>' . $e->getMessage() . '</comment>');
        }

        $output->writeln('<info>Migrating plugin tables...</info>');
        $this->runProcess($this->php . ' artisan october:up', 'Plugin migrations failed!');

        $output->writeln('<info>Setting up deployments...</info>');
        try {
            (new DeploymentInstaller($this->config, $this->gitignore, $this->output, $this->php))->install($force);
        } catch (\RuntimeException $e) {
            $output->writeln("<error>${e}</error>");
        }

        $output->writeln('<info>Creating .gitignore...</info>');
        $this->gitignore->write();

        if ($this->firstRun) {
            $output->writeln('<info>Removing demo data...</info>');
            $this->runProcess($this->php . ' artisan october:fresh', 'Failed to remove demo data!');

            $output->writeln('<info>Creating README...</info>');
            $this->readme();

            $output->writeln('<info>Cleaning up...</info>');
            $this->cleanup();
        }

        $output->writeln('<info>Clearing cache...</info>');
        $this->runProcess($this->php . ' artisan clear-compiled', 'Failed to clear compiled files!');
        $this->runProcess($this->php . ' artisan cache:clear', 'Failed to clear cache!');

        $output->writeln('<comment>Application ready! Build something amazing.</comment>');

        return true;
    }

    /**
     * Create the .env and config files.
     *
     * @param bool $force
     */
    protected function writeConfig($force = false)
    {
        $setup = new Setup($this->config, $this->output, $this->php);
        $setup->config();

        if ($this->firstRun) {
            $setup->env(false, true);

            return;
        }

        if ((file_exists(getcwd() . DS . '.env') && $force === false)) {
            return $this->output->writeln('<comment>-> Configuration already set up. Use --force to regenerate.</comment>');
        }

        $setup->env();

    }

    /**
     * Get the .gitignore template.
     *
     * @return string
     */
    protected function getGitignore()
    {
        $target = getcwd() . DS . '.gitignore';
        if (file_exists($target)) {
            return $target;
        }

        $template = $this->getTemplate('gitignore');

        copy($template, $target);

        return $target;
    }

    /**
     * Copy the README template.
     *
     * @return void
     */
    protected function readme()
    {
        $template = $this->getTemplate('README.md');
        copy($template, getcwd() . DS . 'README.md');
    }

    protected function cleanup()
    {
        if ( ! $this->firstRun) {
            return;
        }

        $remove = ['CONTRIBUTING.md', 'CHANGELOG.md', 'ISSUE_TEMPLATE.md'];
        foreach ($remove as $file) {
            @unlink(getcwd() . DS . $file);
        }
    }

    /**
     * Prepare database before migrations.
     */
    public function prepareDatabase()
    {
        // If SQLite database does not exist, create it
        if ($this->config->database['connection'] === 'sqlite') {
            $path = $this->config->database['database'];
            if ( ! file_exists($path) && is_dir(dirname($path))) {
                $this->output->writeln("<info>Creating $path ...</info>");
                touch($path);
            }
        }
    }
}
