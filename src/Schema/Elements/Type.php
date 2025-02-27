<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Schema\Elements;

use Nette;
use Nette\Schema\Context;
use Nette\Schema\DynamicParameter;
use Nette\Schema\Helpers;
use Nette\Schema\Schema;


final class Type implements Schema
{
	use Base;
	use Nette\SmartObject;

	private string $type;
	private ?Schema $itemsValue = null;
	private ?Schema $itemsKey = null;

	/** @var array{?float, ?float} */
	private array $range = [null, null];
	private ?string $pattern = null;
	private bool $merge = false;


	public function __construct(string $type)
	{
		$defaults = ['list' => [], 'array' => []];
		$this->type = $type;
		$this->default = strpos($type, '[]') ? [] : $defaults[$type] ?? null;
	}


	public function nullable(): self
	{
		$this->type = 'null|' . $this->type;
		return $this;
	}


	public function mergeDefaults(bool $state = true): self
	{
		$this->merge = $state;
		return $this;
	}


	public function dynamic(): self
	{
		$this->type = DynamicParameter::class . '|' . $this->type;
		return $this;
	}


	public function min(?float $min): self
	{
		$this->range[0] = $min;
		return $this;
	}


	public function max(?float $max): self
	{
		$this->range[1] = $max;
		return $this;
	}


	/**
	 * @internal  use arrayOf() or listOf()
	 */
	public function items(string|Schema $valueType = 'mixed', string|Schema $keyType = null): self
	{
		$this->itemsValue = $valueType instanceof Schema
			? $valueType
			: new self($valueType);
		$this->itemsKey = $keyType instanceof Schema || $keyType === null
			? $keyType
			: new self($keyType);
		return $this;
	}


	public function pattern(?string $pattern): self
	{
		$this->pattern = $pattern;
		return $this;
	}


	/********************* processing ****************d*g**/


	public function normalize(mixed $value, Context $context): mixed
	{
		if ($prevent = (is_array($value) && isset($value[Helpers::PreventMerging]))) {
			unset($value[Helpers::PreventMerging]);
		}

		$value = $this->doNormalize($value, $context);
		if (is_array($value) && $this->itemsValue) {
			$res = [];
			foreach ($value as $key => $val) {
				$context->path[] = $key;
				$context->isKey = true;
				$key = $this->itemsKey
					? $this->itemsKey->normalize($key, $context)
					: $key;
				$context->isKey = false;
				$res[$key] = $this->itemsValue->normalize($val, $context);
				array_pop($context->path);
			}

			$value = $res;
		}

		if ($prevent && is_array($value)) {
			$value[Helpers::PreventMerging] = true;
		}

		return $value;
	}


	public function merge(mixed $value, $base): mixed
	{
		if (is_array($value) && isset($value[Helpers::PreventMerging])) {
			unset($value[Helpers::PreventMerging]);
			return $value;
		}

		if (is_array($value) && is_array($base) && $this->itemsValue) {
			$index = 0;
			foreach ($value as $key => $val) {
				if ($key === $index) {
					$base[] = $val;
					$index++;
				} else {
					$base[$key] = array_key_exists($key, $base)
						? $this->itemsValue->merge($val, $base[$key])
						: $val;
				}
			}

			return $base;
		}

		return Helpers::merge($value, $base);
	}


	public function complete(mixed $value, Context $context): mixed
	{
		$merge = $this->merge;
		if (is_array($value) && isset($value[Helpers::PreventMerging])) {
			unset($value[Helpers::PreventMerging]);
			$merge = false;
		}

		if ($value === null && is_array($this->default)) {
			$value = []; // is unable to distinguish null from array in NEON
		}

		$this->doDeprecation($context);

		if (!$this->doValidate($value, $this->type, $context)
			|| !$this->doValidateRange($value, $this->range, $context, $this->type)
		) {
			return null;
		}

		if ($value !== null && $this->pattern !== null && !preg_match("\x01^(?:$this->pattern)$\x01Du", $value)) {
			$context->addError(
				"The %label% %path% expects to match pattern '%pattern%', %value% given.",
				Nette\Schema\Message::PatternMismatch,
				['value' => $value, 'pattern' => $this->pattern],
			);
			return null;
		}

		if ($value instanceof DynamicParameter) {
			$expected = $this->type . ($this->range === [null, null] ? '' : ':' . implode('..', $this->range));
			$context->dynamics[] = [$value, str_replace(DynamicParameter::class . '|', '', $expected)];
		}

		if ($this->itemsValue) {
			$errCount = count($context->errors);
			$res = [];
			foreach ($value as $key => $val) {
				$context->path[] = $key;
				$context->isKey = true;
				$key = $this->itemsKey ? $this->itemsKey->complete($key, $context) : $key;
				$context->isKey = false;
				$res[$key] = $this->itemsValue->complete($val, $context);
				array_pop($context->path);
			}

			if (count($context->errors) > $errCount) {
				return null;
			}

			$value = $res;
		}

		if ($merge) {
			$value = Helpers::merge($value, $this->default);
		}

		return $this->doFinalize($value, $context);
	}
}
