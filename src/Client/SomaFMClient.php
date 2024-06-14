<?php

namespace App\Client;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class SomaFMClient
{
	private Client $client;
	private string $channelsUrl = 'https://somafm.com/channels.json';
	private string $cacheFile = '/tmp/somafm_channels.json';
	private ?Process $currentProcess = null;

	public function __construct()
	{
		$this->client = new Client();
	}

	/**
	 * @throws GuzzleException
	 * @throws Exception
	 */
	public function getStations(): array
	{
		if (file_exists($this->cacheFile)) {
			$cacheTime = filemtime($this->cacheFile);
			if (time() - $cacheTime < 86400) {
				return json_decode(file_get_contents($this->cacheFile), true);
			}
		}

		$response = $this->client->get($this->channelsUrl);
		if ($response->getStatusCode() === 200) {
			$data = json_decode($response->getBody(), true);
			file_put_contents($this->cacheFile, json_encode($data['channels']));
			return $data['channels'] ?? [];
		} else {
			throw new Exception("Error fetching stations: " . $response->getStatusCode());
		}
	}

	/**
	 * @throws GuzzleException
	 * @throws Exception
	 */
	public function streamMusic(
		string $stationUrl,
		OutputInterface $output,
		string $stationName,
		bool $showTrack,
		bool $showInfo
	): void {
		$response = $this->client->get($stationUrl, ['stream' => true]);

		if ($response->getStatusCode() === 200) {
			$body = (string)$response->getBody();
			$streamUrl = $this->extractStreamUrl($body);
			if ($streamUrl) {
				if ($showInfo) {
					$this->displayStreamInfoOnce($streamUrl, $output);
				}
				$this->playStream($streamUrl, $output, $stationName, $showTrack);
			} else {
				throw new Exception("Unable to extract stream URL.");
			}
		} else {
			throw new Exception("Error streaming music: " . $response->getStatusCode());
		}
	}

	public function isStreamRunning(): bool
	{
		return $this->currentProcess && $this->currentProcess->isRunning();
	}

	private function extractStreamUrl(string $content): ?string
	{
		if (preg_match('/http\S+/', $content, $matches)) {
			return $matches[0];
		}
		return null;
	}

	private function playStream(string $streamUrl, OutputInterface $output, string $stationName, bool $showTrack): void
	{
		$output->writeln("Now playing: $stationName");

		if ($this->currentProcess && $this->currentProcess->isRunning()) {
			$this->currentProcess->stop();
		}

		$this->currentProcess = new Process(['ffplay', '-nodisp', '-autoexit', $streamUrl]);
		$this->currentProcess->start();

		if ($showTrack) {
			$this->displayStreamTitle($streamUrl, $output);
		}
	}

	public function stopCurrentStream(): void
	{
		if ($this->currentProcess && $this->currentProcess->isRunning()) {
			$this->currentProcess->stop();
		}
	}

	private function displayStreamTitle(string $streamUrl, OutputInterface $output): void
	{
		$lastStreamTitle = '';
		while ($this->currentProcess && $this->currentProcess->isRunning()) {
			$process = new Process(
				[
					'ffprobe',
					'-v',
					'quiet',
					'-print_format',
					'json',
					'-show_entries',
					'format_tags=StreamTitle',
					$streamUrl
				]
			);
			$process->run();

			if ($process->isSuccessful()) {
				$streamInfo = json_decode($process->getOutput(), true);
				if (isset($streamInfo['format']['tags']['StreamTitle'])) {
					$streamTitle = $streamInfo['format']['tags']['StreamTitle'];
					if ($streamTitle !== $lastStreamTitle) {
						$output->writeln("<info>StreamTitle:</info> $streamTitle");
						$lastStreamTitle = $streamTitle;
					}
				}
			} else {
				$output->writeln('<error>Failed to retrieve stream information.</error>');
			}
			sleep(1);
		}
	}

	private function displayStreamInfoOnce(string $streamUrl, OutputInterface $output): void
	{
		$process = new Process(
			['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_entries', 'format_tags', $streamUrl]
		);
		$process->run();

		if ($process->isSuccessful()) {
			$streamInfo = json_decode($process->getOutput(), true);
			$output->writeln('<info>Stream Information:</info>');
			$this->prettyPrintStreamInfo($streamInfo['format']['tags'], $output);
		} else {
			$output->writeln('<error>Failed to retrieve stream information.</error>');
		}
	}

	private function prettyPrintStreamInfo(array $info, OutputInterface $output): void
	{
		foreach ($info as $key => $value) {
			$output->writeln("<info>$key:</info> $value");
		}
	}

	public function getBestPlaylist(array $playlists, string $format, string $quality): ?string
	{
		$qualityMap = ['highest' => 3, 'high' => 2, 'low' => 1];
		$selectedPlaylist = null;

		foreach ($playlists as $playlist) {
			if ($playlist['format'] === $format && $qualityMap[$playlist['quality']] === $qualityMap[$quality]) {
				return $playlist['url'];
			}
		}

		foreach ($playlists as $playlist) {
			if ($playlist['format'] === $format) {
				return $playlist['url'];
			}
		}

		return $playlists[0]['url'] ?? null;
	}
}
