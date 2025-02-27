<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Schema\Elements;

use Nette;
use Nette\Schema\Context;


/**
 * @internal
 */
trait Base
{
	private bool $required = false;
	private mixed $default = null;

	/** @var ?callable */
	private $before;

	/** @var array[] */
	private array $asserts = [];
	private ?string $castTo = null;
	private ?string $deprecated = null;


	public function default($value): self
	{
		$this->default = $value;
		return $this;
	}


	public function required(bool $state = true): self
	{
		$this->required = $state;
		return $this;
	}


	public function before(callable $handler): self
	{
		$this->before = $handler;
		return $this;
	}


	public function castTo(string $type): self
	{
		$this->castTo = $type;
		return $this;
	}


	public function assert(callable $handler, ?string $description = null): self
	{
		$this->asserts[] = [$handler, $description];
		return $this;
	}


	/** Marks as deprecated */
	public function deprecated(string $message = 'The item %path% is deprecated.'): self
	{
		$this->deprecated = $message;
		return $this;
	}


	public function completeDefault(Context $context): mixed
	{
		if ($this->required) {
			$context->addError(
				'The mandatory item %path% is missing.',
				Nette\Schema\Message::MissingItem,
			);
			return null;
		}

		return $this->default;
	}


	public function doNormalize(mixed $value, Context $context): mixed
	{
		if ($this->before) {
			$value = ($this->before)($value);
		}

		return $value;
	}


	private function doDeprecation(Context $context): void
	{
		if ($this->deprecated !== null) {
			$context->addWarning(
				$this->deprecated,
				Nette\Schema\Message::Deprecated,
			);
		}
	}


	private function doValidate(mixed $value, string $expected, Context $context): bool
	{
		if (!Nette\Utils\Validators::is($value, $expected)) {
			$expected = str_replace(['|', ':'], [' or ', ' in range '], $expected);
			$context->addError(
				'The %label% %path% expects to be %expected%, %value% given.',
				Nette\Schema\Message::TypeMismatch,
				['value' => $value, 'expected' => $expected],
			);
			return false;
		}

		return true;
	}


	private function doValidateRange(mixed $value, array $range, Context $context, string $types = ''): bool
	{
		if (is_array($value) || is_string($value)) {
			[$length, $label] = is_array($value)
				? [count($value), 'items']
				: (in_array('unicode', explode('|', $types), true)
					? [Nette\Utils\Strings::length($value), 'characters']
					: [strlen($value), 'bytes']);

			if (!self::isInRange($length, $range)) {
				$context->addError(
					"The length of %label% %path% expects to be in range %expected%, %length% $label given.",
					Nette\Schema\Message::LengthOutOfRange,
					['value' => $value, 'length' => $length, 'expected' => implode('..', $range)],
				);
				return false;
			}
		} elseif ((is_int($value) || is_float($value)) && !self::isInRange($value, $range)) {
			$context->addError(
				'The %label% %path% expects to be in range %expected%, %value% given.',
				Nette\Schema\Message::ValueOutOfRange,
				['value' => $value, 'expected' => implode('..', $range)],
			);
			return false;
		}

		return true;
	}


	private function isInRange(mixed $value, array $range): bool
	{
		return ($range[0] === null || $value >= $range[0])
			&& ($range[1] === null || $value <= $range[1]);
	}


	private function doFinalize(mixed $value, Context $context)
	{
		if ($this->castTo) {
			if (Nette\Utils\Validators::isBuiltinType($this->castTo)) {
				settype($value, $this->castTo);
			} elseif (strcasecmp($this->castTo, \stdClass::class) === 0) {
				$value = Nette\Utils\Arrays::toObject($value, new $this->castTo);
			} else {
				$value = is_array($value)
					? new ($this->castTo)(...$value)
					: new ($this->castTo)($value);
			}
		}

		foreach ($this->asserts as $i => [$handler, $description]) {
			if (!$handler($value)) {
				$expected = $description ?: (is_string($handler) ? "$handler()" : "#$i");
				$context->addError(
					'Failed assertion ' . ($description ? "'%assertion%'" : '%assertion%') . ' for %label% %path% with value %value%.',
					Nette\Schema\Message::FailedAssertion,
					['value' => $value, 'assertion' => $expected],
				);
				return;
			}
		}

		return $value;
	}
}
