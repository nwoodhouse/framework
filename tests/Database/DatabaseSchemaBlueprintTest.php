<?php

namespace Illuminate\Tests\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Grammars\SqlServerGrammar;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\DatabaseNotification;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class DatabaseSchemaBlueprintTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        Builder::$defaultMorphKeyType = 'int';
    }

    public function testToSqlRunsCommandsFromBlueprint()
    {
        $conn = m::mock(Connection::class);
        $conn->shouldReceive('statement')->once()->with('foo');
        $conn->shouldReceive('statement')->once()->with('bar');
        $grammar = m::mock(MySqlGrammar::class);
        $blueprint = $this->getMockBuilder(Blueprint::class)->onlyMethods(['toSql'])->setConstructorArgs(['users'])->getMock();
        $blueprint->expects($this->once())->method('toSql')->with($this->equalTo($conn), $this->equalTo($grammar))->willReturn(['foo', 'bar']);

        $blueprint->build($conn, $grammar);
    }

    public function testIndexDefaultNames()
    {
        $blueprint = new Blueprint('users');
        $blueprint->unique(['foo', 'bar']);
        $commands = $blueprint->getCommands();
        $this->assertSame('users_foo_bar_unique', $commands[0]->index);

        $blueprint = new Blueprint('users');
        $blueprint->index('foo');
        $commands = $blueprint->getCommands();
        $this->assertSame('users_foo_index', $commands[0]->index);

        $blueprint = new Blueprint('geo');
        $blueprint->spatialIndex('coordinates');
        $commands = $blueprint->getCommands();
        $this->assertSame('geo_coordinates_spatialindex', $commands[0]->index);
    }

    public function testIndexDefaultNamesWhenPrefixSupplied()
    {
        $blueprint = new Blueprint('users', null, 'prefix_');
        $blueprint->unique(['foo', 'bar']);
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_users_foo_bar_unique', $commands[0]->index);

        $blueprint = new Blueprint('users', null, 'prefix_');
        $blueprint->index('foo');
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_users_foo_index', $commands[0]->index);

        $blueprint = new Blueprint('geo', null, 'prefix_');
        $blueprint->spatialIndex('coordinates');
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_geo_coordinates_spatialindex', $commands[0]->index);
    }

    public function testDropIndexDefaultNames()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropUnique(['foo', 'bar']);
        $commands = $blueprint->getCommands();
        $this->assertSame('users_foo_bar_unique', $commands[0]->index);

        $blueprint = new Blueprint('users');
        $blueprint->dropIndex(['foo']);
        $commands = $blueprint->getCommands();
        $this->assertSame('users_foo_index', $commands[0]->index);

        $blueprint = new Blueprint('geo');
        $blueprint->dropSpatialIndex(['coordinates']);
        $commands = $blueprint->getCommands();
        $this->assertSame('geo_coordinates_spatialindex', $commands[0]->index);
    }

    public function testDropIndexDefaultNamesWhenPrefixSupplied()
    {
        $blueprint = new Blueprint('users', null, 'prefix_');
        $blueprint->dropUnique(['foo', 'bar']);
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_users_foo_bar_unique', $commands[0]->index);

        $blueprint = new Blueprint('users', null, 'prefix_');
        $blueprint->dropIndex(['foo']);
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_users_foo_index', $commands[0]->index);

        $blueprint = new Blueprint('geo', null, 'prefix_');
        $blueprint->dropSpatialIndex(['coordinates']);
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_geo_coordinates_spatialindex', $commands[0]->index);
    }

    public function testDefaultCurrentDateTime()
    {
        $base = new Blueprint('users', function ($table) {
            $table->dateTime('created')->useCurrent();
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;
        $this->assertEquals(['alter table `users` add `created` datetime default CURRENT_TIMESTAMP not null'], $blueprint->toSql($connection, new MySqlGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['alter table "users" add column "created" timestamp(0) without time zone default CURRENT_TIMESTAMP not null'], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['alter table "users" add column "created" datetime default CURRENT_TIMESTAMP not null'], $blueprint->toSql($connection, new SQLiteGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['alter table "users" add "created" datetime default CURRENT_TIMESTAMP not null'], $blueprint->toSql($connection, new SqlServerGrammar));
    }

    public function testDefaultCurrentTimestamp()
    {
        $base = new Blueprint('users', function ($table) {
            $table->timestamp('created')->useCurrent();
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;
        $this->assertEquals(['alter table `users` add `created` timestamp default CURRENT_TIMESTAMP not null'], $blueprint->toSql($connection, new MySqlGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['alter table "users" add column "created" timestamp(0) without time zone default CURRENT_TIMESTAMP not null'], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['alter table "users" add column "created" datetime default CURRENT_TIMESTAMP not null'], $blueprint->toSql($connection, new SQLiteGrammar));

        $blueprint = clone $base;
        $this->assertEquals(['alter table "users" add "created" datetime default CURRENT_TIMESTAMP not null'], $blueprint->toSql($connection, new SqlServerGrammar));
    }

    public function testUnsignedDecimalTable()
    {
        $base = new Blueprint('users', function ($table) {
            $table->unsignedDecimal('money', 10, 2)->useCurrent();
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;
        $this->assertEquals(['alter table `users` add `money` decimal(10, 2) unsigned not null'], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testRemoveColumn()
    {
        $base = new Blueprint('users', function ($table) {
            $table->string('foo');
            $table->string('remove_this');
            $table->removeColumn('remove_this');
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;

        $this->assertEquals(['alter table `users` add `foo` varchar(255) not null'], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testMacroable()
    {
        Blueprint::macro('foo', function () {
            return $this->addCommand('foo');
        });

        MySqlGrammar::macro('compileFoo', function () {
            return 'bar';
        });

        $blueprint = new Blueprint('users', function ($table) {
            $table->foo();
        });

        $connection = m::mock(Connection::class);

        $this->assertEquals(['bar'], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testDefaultUsingIdMorph()
    {
        $base = new Blueprint('comments', function ($table) {
            $table->morphs('commentable');
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) not null, add `commentable_id` bigint unsigned not null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testDefaultUsingNullableIdMorph()
    {
        $base = new Blueprint('comments', function ($table) {
            $table->nullableMorphs('commentable');
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) null, add `commentable_id` bigint unsigned null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testDefaultUsingUuidMorph()
    {
        Builder::defaultMorphKeyType('uuid');

        $base = new Blueprint('comments', function ($table) {
            $table->morphs('commentable');
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) not null, add `commentable_id` char(36) not null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testDefaultUsingNullableUuidMorph()
    {
        Builder::defaultMorphKeyType('uuid');

        $base = new Blueprint('comments', function ($table) {
            $table->nullableMorphs('commentable');
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) null, add `commentable_id` char(36) null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testGenerateRelationshipColumnWithIncrementalModel()
    {
        $base = new Blueprint('posts', function ($table) {
            $table->foreignIdFor('Illuminate\Foundation\Auth\User');
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;

        $this->assertEquals([
            'alter table `posts` add `user_id` bigint unsigned not null',
        ], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testGenerateMultipleRelationshipColumns()
    {
        $this->runBlueprintAssertions('some_table', function (Blueprint $table) {
            $table->foreignIdsFor([
                User::class,
                User::class => 'author_id',
            ]);
        }, [
            'alter table `some_table` add `user_id` bigint unsigned not null, add `author_id` bigint unsigned not null',
        ]);
    }

    public function testGenerateMultipleRelationshipColumnsWithCallback()
    {
        $this->runBlueprintAssertions('some_table', function (Blueprint $table) {
            $table->foreignIdsFor([
                DatabaseNotification::class => function (ForeignIdColumnDefinition $definition) {
                    $definition->nullable();
                },
            ]);
        }, [
            'alter table `some_table` add `database_notification_id` char(36) null',
        ]);
    }

    public function testGenerateMultipleRelationshipColumnsWithHigherOrderCallback()
    {
        $this->runBlueprintAssertions('some_table', function (Blueprint $table) {
            $table->foreignIdsFor([User::class])->each->nullable();
        }, [
            'alter table `some_table` add `user_id` bigint unsigned null',
        ]);
    }

    public function testGenerateMultipleRelationshipColumnsWithCallbackAndColumnDefinition()
    {
        $this->runBlueprintAssertions('some_table', function (Blueprint $table) {
            $table->foreignIdsFor([
                User::class,
                User::class => [
                    'author_id',
                    function (ForeignIdColumnDefinition $definition) {
                        $definition->nullable();
                    },
                ],
            ]);
        }, [
            'alter table `some_table` add `user_id` bigint unsigned not null, add `author_id` bigint unsigned null',
        ]);
    }

    private function runBlueprintAssertions(string $table, \Closure $closure, array $statements)
    {
        $base = new Blueprint($table, $closure);

        $connection = m::mock(Connection::class);
        $blueprint = clone $base;

        $this->assertEquals($statements, $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testGenerateRelationshipColumnWithUuidModel()
    {
        require_once __DIR__.'/stubs/EloquentModelUuidStub.php';

        $base = new Blueprint('posts', function ($table) {
            $table->foreignIdFor('EloquentModelUuidStub');
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;

        $this->assertEquals([
            'alter table `posts` add `eloquent_model_uuid_stub_id` char(36) not null',
        ], $blueprint->toSql($connection, new MySqlGrammar));
    }

    public function testTinyTextColumn()
    {
        $base = new Blueprint('posts', function ($table) {
            $table->tinyText('note');
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;
        $this->assertEquals([
            'alter table `posts` add `note` tinytext not null',
        ], $blueprint->toSql($connection, new MySqlGrammar));

        $blueprint = clone $base;
        $this->assertEquals([
            'alter table "posts" add column "note" text not null',
        ], $blueprint->toSql($connection, new SQLiteGrammar));

        $blueprint = clone $base;
        $this->assertEquals([
            'alter table "posts" add column "note" varchar(255) not null',
        ], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = clone $base;
        $this->assertEquals([
            'alter table "posts" add "note" nvarchar(255) not null',
        ], $blueprint->toSql($connection, new SqlServerGrammar));
    }

    public function testTinyTextNullableColumn()
    {
        $base = new Blueprint('posts', function ($table) {
            $table->tinyText('note')->nullable();
        });

        $connection = m::mock(Connection::class);

        $blueprint = clone $base;
        $this->assertEquals([
            'alter table `posts` add `note` tinytext null',
        ], $blueprint->toSql($connection, new MySqlGrammar));

        $blueprint = clone $base;
        $this->assertEquals([
            'alter table "posts" add column "note" text',
        ], $blueprint->toSql($connection, new SQLiteGrammar));

        $blueprint = clone $base;
        $this->assertEquals([
            'alter table "posts" add column "note" varchar(255) null',
        ], $blueprint->toSql($connection, new PostgresGrammar));

        $blueprint = clone $base;
        $this->assertEquals([
            'alter table "posts" add "note" nvarchar(255) null',
        ], $blueprint->toSql($connection, new SqlServerGrammar));
    }
}
