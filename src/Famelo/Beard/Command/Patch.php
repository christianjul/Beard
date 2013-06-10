<?php

namespace Famelo\Beard\Command;

use Famelo\Beard\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Traversable;

/**
 * Builds a new Phar.
 *
 */
class Patch extends Command {
	/**
	 * The Box instance.
	 *
	 * @var Box
	 */
	private $box;

	/**
	 * The configuration settings.
	 *
	 * @var Configuration
	 */
	private $config;

	/**
	 * The output handler.
	 *
	 * @var OutputInterface
	 */
	private $output;

	/**
	 * @override
	 */
	protected function configure() {
		parent::configure();
		$this->setName('patch');
		$this->setDescription('Patch and update respositories based on beard.json');
	}

	/**
	 * @override
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->output = $output;
		$this->input = $input;
		$this->baseDir = getcwd();

		$configFile = 'beard.json';

		if (!file_exists($configFile)) {
			if (file_exists('gerrit.json') && file_exists('Packages/Libraries/composer/autoload_namespaces.php')) {
				$data = file_get_contents('gerrit.json');
				$namespaces = include_once('Packages/Libraries/composer/autoload_namespaces.php');
				$packages = json_decode($data);
				foreach ($packages as $packageName => $changes) {
					$namespace = str_replace('.', '\\', $packageName);
					$path = $namespaces[$namespace][0];

					foreach ($changes as $name => $changeId) {
						$change = new \StdClass();
						$change->type = 'gerrit';
						$change->name = $name;
						$change->change_id = $changeId;
						$change->gerrit_git = 'git.typo3.org';
						$change->gerrit_api_endpoint = 'https://review.typo3.org/';
						$change->path = $path;

						$this->applyChange($change);
					}
				}
				#var_dump($packages, $namespaces);
				exit();
			} else {
				$this->output->write('<comment>No beard.json found!</comment>' . chr(10));
				return;
			}
		} else {
			$config = $this->getConfig($configFile);

			$this->baseDir = getcwd();
			foreach ($config->getChanges() as $change) {
				$this->applyChange($change);
			}
		}
	}

	public function getConfig($filename) {
		$helper = $this->getHelper('config');
		return $helper->loadFile($filename);
	}

	public function applyChange($change) {
		$repository = str_replace($this->baseDir . '/', '', $change->path);
		$this->output->writeln($repository . ': ' . $change->name);

		if (!is_dir($change->path)) {
			$this->output->writeln('<error>The directory ' . $change->path . ' doesn\'t exist!</error>');
			return;
		}

		chdir($change->path);

		switch($change->type) {
			case 'gerrit': $this->applyGerritChange($change);
				break;
			case 'patch':
			case 'diff': $this->applyDiffChange($change);
				break;
			default: $this->output->write('woot?');
		}

		chdir($this->baseDir);
		$this->output->writeln('');
	}

	public function applyGerritChange($change) {
		$commits = $this->executeShellCommand('git log -n30');
		$changeInformation = $this->fetchChangeInformation($change);

		if ($changeInformation->status == 'MERGED') {
			$this->output->write('<comment>This change has been merged!</comment>' . chr(10));
		} elseif ($changeInformation->status == 'ABANDONED') {
			$this->output->write('<error>This change has been abandoned!</error>' . chr(10));
		} else {
			$command = 'git fetch --quiet git://' . $change->gerrit_git . '/' . $changeInformation->project . ' ' . $changeInformation->revisions->{$changeInformation->current_revision}->fetch->git->ref . '';
			$output = $this->executeShellCommand($command);

			$commit = $this->executeShellCommand('git log --format="%H" -n1 FETCH_HEAD');
			if (stristr($commits, '(cherry picked from commit ' . $commit . ')') !== FALSE) {
				$this->output->write('<comment>Already picked</comment>' . chr(10));
			} else {
				echo $output;
				system('git cherry-pick -x --strategy=recursive -X theirs FETCH_HEAD');
			}
		}
	}

	public function applyDiffChange($change) {
		$file = $this->baseDir . '/' . $change->file;
		if (!file_exists($this->baseDir . '/' . $change->file)) {
			$this->output->write('<error>The file ' . $change->file . ' doesn\'t exist!</error>' . chr(10));
		}
		$output = $this->executeShellCommand('git apply ' . $file . ' --verbose');
		echo $output;
	}

	public function executeShellCommand($command) {
		$output = '';
		$fp = popen($command, 'r');
		while (($line = fgets($fp)) !== FALSE) {
			$output .= $line;
		}
		pclose($fp);
		return trim($output);
	}

	/**
	 * @param integer $changeId The numeric change id, not the hash
	 * @return mixed
	 */
	public function fetchChangeInformation($change) {
		$output = file_get_contents($change->gerrit_api_endpoint . 'changes/?q=' . intval($change->change_id) . '&o=CURRENT_REVISION');

			// Remove first line
		$output = substr($output, strpos($output, "\n") + 1);
			// trim []
		$output = ltrim($output, '[');
		$output = rtrim(rtrim($output), ']');

		$data = json_decode($output);
		return $data;
	}

}

?>