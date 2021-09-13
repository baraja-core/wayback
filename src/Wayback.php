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
		$cachePath = $cachePath ?? sys_get_temp_dir() . '/wayback';
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
			$return[$hostDomain] = new \DateTimeImmutable($firstSeen);
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
				static function (array $a, array $b): int
				{
					return $a[1] < $b[1] ? 1 : -1;
				}
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
