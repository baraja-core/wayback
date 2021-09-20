<?php

declare(strict_types=1);

namespace Baraja\Wayback;


use Nette\Caching\Cache;
use Nette\Caching\Storages\FileStorage;
use Nette\Http\Url;
use Nette\Utils\FileSystem;
use Nette\Utils\Validators;

final class Wayback
{
	private Cache $cache;


	public function __construct(?string $cachePath = null)
	{
		$cachePath ??= sys_get_temp_dir() . '/wayback';
		FileSystem::createDir($cachePath);
		$this->cache = new Cache(new FileStorage($cachePath), 'wayback');
	}


	/**
	 * @return array<int, WaybackLink>
	 */
	public function getArchivedUrlsByHost(string $url): array
	{
		$urlEntity = new Url($this->normalizeUrl($url));

		return $this->getArchivedUrls('https://' . $urlEntity->getDomain(5));
	}


	/**
	 * @return array<int, WaybackLink>
	 */
	public function getArchivedUrls(string $url): array
	{
		$urlEntity = new Url($this->normalizeUrl($url));
		$url = str_replace($urlEntity->getScheme() . '://', '', $urlEntity->getAbsoluteUrl());

		return $this->getResults($url);
	}


	public function convertDateTimeToUTC(\DateTimeInterface $dateTime): \DateTime
	{
		return (new \DateTime($dateTime->format('Y-m-d H:i:s')))
			->setTimezone(new \DateTimeZone('UTC'));
	}


	public function formatDateTime(\DateTimeInterface|string $dateTime): string
	{
		if (is_string($dateTime)) {
			$dateTime = $this->parseDateTime($dateTime);
		}

		return $this->convertDateTimeToUTC($dateTime)->format('YmdHis');
	}


	public function parseDateTime(string $dateTime): \DateTimeImmutable
	{
		if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(?:id_)?$/', $dateTime, $parser) === 1) {
			assert(isset($parser[1], $parser[2], $parser[3], $parser[4], $parser[5], $parser[6]));

			return new \DateTimeImmutable(
				$parser[1] . '-' . $parser[2] . '-' . $parser[3]
				. ' ' . $parser[4] . ':' . $parser[5] . ':' . $parser[6],
				new \DateTimeZone('UTC'),
			);
		}
		throw new \InvalidArgumentException('Haystack "' . $dateTime . '" is not valid Wayback datetime.');
	}


	public function getClosedArchivedFile(string $url, \DateTimeInterface|string $dateTime): ?string
	{
		return $this->getArchivedFile('http://web.archive.org/web/' . $this->formatDateTime($dateTime) . 'id_/' . $url);
	}


	public function getClosedArchivedDateTime(string $url, \DateTimeInterface|string $dateTime): ?string
	{
		$rawUrl = $this->getRawUrl($url, $dateTime);
		$headers = get_headers($rawUrl, true);
		if ($headers === false) {
			throw new \InvalidArgumentException('URL "' . $url . '" is not callable.');
		}
		$httpCode = null;
		if (isset($headers[0]) && preg_match('/^HTTP\/(?:\d+(?:\.\d+)?)?\s+(\d+)/', (string) $headers[0], $p) === 1) {
			$httpCode = (int) $p[1];
		}
		if ($httpCode === null) {
			throw new \LogicException('Can not parse HTTP status code.' . "\n\n" . implode("\n", $headers));
		} elseif ($httpCode === 200) { // ok
			return $this->formatDateTime($dateTime);
		} elseif ($httpCode >= 300 && $httpCode <= 399) { // redirect
			if (
				isset($headers['location'])
				&& preg_match('/web\/(\d{14})/', (string) $headers['location'], $locationParser) === 1
			) {
				return $this->formatDateTime($locationParser[1] ?? '');
			} else {
				throw new \LogicException('Can not parse redirect location.');
			}
		} elseif ($httpCode >= 400 && $httpCode <= 499) { // file not found
			return null;
		} elseif ($httpCode >= 500 && $httpCode <= 599) { // server error
			throw new \RuntimeException('Server error: ' . ($headers[0] ?? '') . "\n" . $rawUrl);
		}

		throw new \LogicException('Invalid response code, because "' . $httpCode . '" given.');
	}


	public function getArchivedFile(string $waybackUrl): ?string
	{
		$rawUrl = $this->getRawUrl($waybackUrl);
		$key = 'raw-' . md5($rawUrl);
		$cache = $this->cache->load($key);
		if ($cache === null) {
			try {
				$cache = FileSystem::read($rawUrl);
				$this->cache->save(
					$key,
					$cache,
					[
						Cache::EXPIRATION => '7 days',
					]
				);
			} catch (\Throwable) {
			}
		}

		return $cache;
	}


	/**
	 * @return array<string, \DateTimeImmutable>
	 */
	public function getSubdomains(string $host): array
	{
		$url = new Url($this->normalizeUrl($host));
		$domain = $url->getDomain();
		$cache = $this->cache->load('host-' . $domain);
		if ($cache === null) {
			$api = FileSystem::read(
				'http://web.archive.org/cdx/search/cdx?url='
				. urlencode($domain)
				. '&output=json&matchType=domain&limit=-10000'
			);
			$records = json_decode($api, true, 512, JSON_THROW_ON_ERROR);
			unset($records[0]);

			$hosts = [];
			$hosts[$domain] = new \DateTimeImmutable('now');
			foreach ($records as $record) {
				$recordHost = (new Url($record[2] ?? ''))->getHost();
				$date = new \DateTimeImmutable(
					(string) preg_replace('/^(\d{4})(\d{2})(\d{2}).+$/', '$1-$2-$3', $record[1] ?? '')
				);
				if (
					isset($hosts[$recordHost]) === false
					|| (isset($hosts[$recordHost]) && $date < $hosts[$recordHost])
				) {
					$hosts[$recordHost] = $date;
				}
			}
			$cache = [];
			foreach ($hosts as $hostDomain => $firstSeen) {
				$cache[$hostDomain] = $firstSeen->format('Y-m-d');
			}
			$this->cache->save(
				'host-' . $domain,
				$cache,
				[
					Cache::EXPIRE => '30 minutes',
				],
			);
		}

		$return = [];
		foreach ($cache as $hostDomain => $firstSeen) {
			$return[(string) $hostDomain] = new \DateTimeImmutable($firstSeen);
		}

		return $return;
	}


	public function saveUrl(string $url): void
	{
		if (Validators::isUrl($url) === false) {
			throw new \InvalidArgumentException('URL to save is not valid, because "' . $url . '" given.');
		}
		FileSystem::read('https://web.archive.org/save/' . urlencode($url));
	}


	public function normalizeUrl(string $url): string
	{
		return 'https://' . preg_replace('/^(https?:)?(\/\/)?(www\.)?/', '', $url);
	}


	public function getRawUrl(string $url, \DateTimeInterface|string|null $dateTime = null): string
	{
		if (
			preg_match(
				'/^(?:https?)(:\/\/(?:www)?web\.archive\.org\/web\/)(\d+)(?:id_)?\/(.+)$/',
				$url,
				$parser
			) === 1
		) {
			assert(isset($parser[1], $parser[2], $parser[3]));
			if (Validators::isUrl($parser[3]) === false) {
				throw new \InvalidArgumentException('Given Wayback machine URL does not contain mandatory URL.');
			}

			return 'https' . $parser[1] . $parser[2] . 'id_' . '/' . $parser[3];
		} elseif ($dateTime !== null && Validators::isUrl($url)) {
			return 'https://web.archive.org/web/' . $this->formatDateTime($dateTime) . 'id_/' . $url;
		} else {
			throw new \InvalidArgumentException('Given URL is not valid Wayback machine URL.');
		}
	}


	/**
	 * @return array<int, WaybackLink>
	 */
	private function getResults(string $url): array
	{
		$cache = $this->cache->load($url);
		if ($cache === null) {
			$apiUrl = 'http://web.archive.org/cdx/search/cdx?url=' . urlencode($url) . '&output=json&limit=-1024';
			$cache = json_decode(FileSystem::read($apiUrl), true, 512, JSON_THROW_ON_ERROR);
			unset($cache[0]);

			usort(
				$cache,
				static fn(array $a, array $b): int => $a[1] < $b[1] ? 1 : -1
			);

			$this->cache->save(
				$url,
				$cache,
				[
					Cache::EXPIRE => $cache === [] ? '10 minutes' : '3 days',
				]
			);
		}

		$timezone = new \DateTimeZone('UTC');
		$return = [];
		foreach ($cache as $item) {
			assert(isset($item[1], $item[2], $item[3], $item[4], $item[6]));
			$date = (string) preg_replace(
				'/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/',
				'$1-$2-$3 $4:$5:$6',
				$item[1],
			);
			$return[] = new WaybackLink(
				link: 'http://web.archive.org/web/' . $item[1] . '/' . $item[2],
				mimeType: $item[3],
				statusCode: (int) $item[4],
				length: (int) $item[6],
				date: new \DateTimeImmutable($date, $timezone),
			);
		}

		return $return;
	}
}
