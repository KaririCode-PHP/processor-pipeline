<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Tests\Unit\Attribute;

use KaririCode\ProcessorPipeline\Attribute\Process;
use PHPUnit\Framework\TestCase;

final class ProcessTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $attribute = new Process();

        $this->assertSame([], $attribute->processors);
        $this->assertSame([], $attribute->messages);
    }

    public function testConstructWithProcessors(): void
    {
        $attribute = new Process(
            processors: ['trim', 'email'],
        );

        $this->assertSame(['trim', 'email'], $attribute->processors);
        $this->assertSame([], $attribute->messages);
    }

    public function testConstructWithMessages(): void
    {
        $attribute = new Process(
            processors: ['required'],
            messages: ['required' => 'This field is required.'],
        );

        $this->assertSame(['required'], $attribute->processors);
        $this->assertSame(['required' => 'This field is required.'], $attribute->messages);
    }

    public function testConstructWithConfiguredProcessors(): void
    {
        $attribute = new Process(
            processors: [
                'trim',
                'length' => ['minLength' => 3, 'maxLength' => 50],
            ],
        );

        $this->assertSame('trim', $attribute->processors[0]);
        $this->assertSame(['minLength' => 3, 'maxLength' => 50], $attribute->processors['length']);
    }

    public function testAttributeIsReadableViaReflection(): void
    {
        $class = new class {
            #[Process(
                processors: ['required', 'email'],
                messages: ['email' => 'Invalid email.'],
            )]
            public string $email = '';
        };

        $reflection = new \ReflectionProperty($class, 'email');
        $attributes = $reflection->getAttributes(Process::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame(['required', 'email'], $instance->processors);
        $this->assertSame(['email' => 'Invalid email.'], $instance->messages);
    }

    public function testAttributeIsRepeatable(): void
    {
        $class = new class {
            #[Process(processors: ['trim'])]
            #[Process(processors: ['email'])]
            public string $email = '';
        };

        $reflection = new \ReflectionProperty($class, 'email');
        $attributes = $reflection->getAttributes(Process::class);

        $this->assertCount(2, $attributes);

        $first = $attributes[0]->newInstance();
        $second = $attributes[1]->newInstance();

        $this->assertSame(['trim'], $first->processors);
        $this->assertSame(['email'], $second->processors);
    }

    public function testAttributeTargetsProperties(): void
    {
        $reflection = new \ReflectionClass(Process::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertCount(1, $attributes);

        $attrInstance = $attributes[0]->newInstance();
        $flags = $attrInstance->flags;

        $this->assertTrue(($flags & \Attribute::TARGET_PROPERTY) !== 0);
        $this->assertTrue(($flags & \Attribute::IS_REPEATABLE) !== 0);
    }
}
