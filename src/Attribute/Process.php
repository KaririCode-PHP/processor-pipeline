<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Attribute;

use Attribute;

/**
 * Marks a class property for pipeline processing.
 *
 * Apply this attribute to properties that should be processed through a
 * specific set of processors. The attribute is consumed by
 * {@see \KaririCode\PropertyInspector\AttributeAnalyzer} at runtime.
 *
 * ARFA 1.3 Compliance
 * ===================
 *
 * P2 (Reactive Flow Composition):
 *   Declarative processor composition via PHP 8.4+ attributes.
 *   The processor list defines the flow: f₁ ∘ f₂ ∘ ... ∘ fₙ.
 *
 * Usage
 * =====
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Attribute\Process;
 *
 * class UserProfile
 * {
 *     #[Process(
 *         processors: ['trim', 'lowercase', 'email'],
 *         messages: [
 *             'email' => 'Invalid email format.',
 *         ],
 *     )]
 *     public private(set) string $email = '';
 *
 *     #[Process(
 *         processors: [
 *             'required',
 *             'length' => ['minLength' => 3, 'maxLength' => 50],
 *         ],
 *         messages: [
 *             'required' => 'Username is required.',
 *             'length' => 'Username must be 3-50 characters.',
 *         ],
 *     )]
 *     public private(set) string $username = '';
 * }
 * ```
 *
 * @since     2.0.0
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Process
{
    /**
     * @param array<int|string, string|array<string, mixed>> $processors Processor specification
     * @param array<string, string>                          $messages   Error key → message overrides
     */
    public function __construct(
        public array $processors = [],
        public array $messages = [],
    ) {}
}
