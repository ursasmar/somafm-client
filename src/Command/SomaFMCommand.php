<?php

namespace App\Command;

use App\Client\SomaFMClient;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class SomaFMCommand extends Command
{
	private SomaFMClient $client;

	public function __construct(SomaFMClient $client)
	{
		parent::__construct();
		$this->client = $client;
	}

	protected function configure(): void
	{
		$this
			->setName('soma:interactive')
			->setDescription('Interactive SomaFM client to list stations and play music.')
			->addOption('show-track', 't', InputOption::VALUE_NONE, 'Show current playing track')
			->addOption('show-info', 'I', InputOption::VALUE_NONE, 'Show full stream information')
			->addOption('stream', 's', InputOption::VALUE_REQUIRED, 'Stream name to auto play')
			->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Select format (aac or mp3)', 'aac')
			->addOption('quality', 'Q', InputOption::VALUE_REQUIRED, 'Select quality (highest, high, low)', 'highest');
	}

	/**
	 * @throws GuzzleException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$showTrack = $input->getOption('show-track');
		$showInfo = $input->getOption('show-info');
		$streamName = $input->getOption('stream');
		$format = $input->getOption('format');
		$quality = $input->getOption('quality');

		if ($streamName) {
			$this->autoPlayStream($streamName, $output, $showTrack, $showInfo, $format, $quality);
		} else {
			$output->writeln("Welcome to the interactive SomaFM client!");

			while (true) {
				$output->writeln("\nCommands:\n1. list - List available stations\n2. play [ID] - Play the station by ID\n3. quit - Exit the client\n");
				$output->write('Enter command: ');

				$handle = fopen("php://stdin", "r");
				$command = trim(fgets($handle));
				fclose($handle);

				if ($command === '1' || $command === 'list') {
					$this->listStations($output);
				} elseif (preg_match('/^(2|play) (\d+)$/', $command, $matches)) {
					$this->playStation((int)$matches[2], $output, $showTrack, $showInfo, $format, $quality);
				} elseif ($command === '3' || $command === 'quit') {
					$output->writeln("Exiting the client. Goodbye!");
					$this->client->stopCurrentStream();
					break;
				} else {
					$output->writeln("Invalid command. Please try again.");
				}
			}
		}

		return Command::SUCCESS;
	}

	/**
	 * @throws GuzzleException
	 */
	private function listStations(OutputInterface $output): void
	{
		$stations = $this->client->getStations();
		$table = new Table($output);
		$table->setHeaders(['ID', 'Title', 'Description']);

		foreach ($stations as $index => $station) {
			$table->addRow([$index + 1, $station['title'], $station['description']]);
		}

		$table->render();
	}

	/**
	 * @throws GuzzleException
	 */
	private function playStation(int $stationId, OutputInterface $output, bool $showTrack, bool $showInfo, string $format, string $quality): void
	{
		$stations = $this->client->getStations();
		$stationIndex = $stationId - 1;
		if (isset($stations[$stationIndex])) {
			$station = $stations[$stationIndex];
			$stationUrl = $this->client->getBestPlaylist($station['playlists'], $format, $quality);
			$stationName = $station['title'];
			$this->client->streamMusic($stationUrl, $output, $stationName, $showTrack, $showInfo);
		} else {
			$output->writeln("Invalid station ID.");
		}
	}

	/**
	 * @throws GuzzleException
	 */
	private function autoPlayStream(string $streamName, OutputInterface $output, bool $showTrack, bool $showInfo, string $format, string $quality): void
	{
		$stations = $this->client->getStations();
		$bestMatch = null;
		$highestSimilarity = 0;

		foreach ($stations as $station) {
			similar_text(strtolower($station['title']), strtolower($streamName), $similarity);
			if ($similarity > $highestSimilarity) {
				$highestSimilarity = $similarity;
				$bestMatch = $station;
			}
		}

		if ($bestMatch) {
			$stationUrl = $this->client->getBestPlaylist($bestMatch['playlists'], $format, $quality);
			$stationName = $bestMatch['title'];
			$output->writeln("Auto-playing stream: $stationName");
			$this->client->streamMusic($stationUrl, $output, $stationName, $showTrack, $showInfo);

			// Ensure the process keeps running
			while ($this->client->isStreamRunning()) {
				sleep(1);
			}
		} else {
			$output->writeln("No stream found matching '$streamName'.");
		}
	}
}
