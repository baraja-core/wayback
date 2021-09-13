<?php

declare(strict_types=1);

namespace Baraja\Wayback;


final class WaybackLink
{
	public function __construct(
		private string $link,
		private string $mimeType,
		private int $statusCode,
		private int $length,
		private \DateTimeImmutable $date,
	) {
	}


	public function getLink(): string
	{
		return $this->link;
	}


	public function getMimeType(): string
	{
		return $this->mimeType;
	}


	public function getStatusCode(): int
	{
		return $this->statusCode;
	}


	public function getLength(): int
	{
		return $this->length;
	}


	public function getDate(): \DateTimeImmutable
	{
		return $this->date;
	}
}
