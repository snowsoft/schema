<?php

declare(strict_types=1);

use Nette\Schema\Elements\Structure;
use Nette\Schema\Expect;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


Assert::with(Structure::class, function () {
	$schema = Expect::from(new class {
		public string $dsn = 'mysql';
		public ?string $user;
		public ?string $password = null;
		public array|int $options = [];
		public bool $debugger = true;
		public mixed $mixed;
		public array $arr = [1];
	});

	Assert::type(Structure::class, $schema);
	Assert::equal([
		'dsn' => Expect::string('mysql'),
		'user' => Expect::type('?string')->required(),
		'password' => Expect::type('?string'),
		'options' => Expect::type('array|int')->default([]),
		'debugger' => Expect::bool(true),
		'mixed' => Expect::mixed()->required(),
		'arr' => Expect::type('array')->default([1]),
	], $schema->items);
	Assert::type('string', $schema->castTo);
});
