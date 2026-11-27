<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline;

use KaririCode\Contract\Processor\ConfigurableProcessor;
use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\Exception\InvalidProcessorConfigurationException;
use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
use KaririCode\ProcessorPipeline\Pipeline\Pipeline;

/**
 * Builds immutable pipelines from a declarative specification.
 *
 * Contents
 * ========
 *
 *  1. Overview                        10. Algorithm and Cost
 *  2. Specification Grammar           11. Building from #[Process] Attributes
 *  3. Resolution Rules                12. Design Decisions
 *  4. ARFA 1.3 Compliance             13. Concurrency and Caching Pipelines
 *  5. Invariants                      14. Anti-Patterns
 *  6. Usage Cookbook                  15. Testing
 *  7. Configuration Isolation         16. Migration from V1.x
 *  8. Fail-Closed Parsing             17. FAQ
 *  9. Error Semantics                18. Composing Specifications
 *                                    19. Option Values Are the Processor's Job
 *                                    20. Pre-configured Prototypes
 *                                    21. Build Lifecycle
 *
 * 1. Overview
 * ===========
 *
 * ProcessorBuilder turns a specification — a PHP array naming processors
 * and, optionally, their options — into an immutable {@see Pipeline}. Names
 * are resolved against one context of a {@see ProcessorRegistry}.
 *
 * - A configured entry runs on a private clone of the registered processor,
 *   so pipelines built with different options never interfere (§7).
 * - Anything outside the grammar fails the build instead of being dropped,
 *   so a typo cannot remove a validator (§8).
 * - The builder holds nothing but the registry reference: it is `readonly`
 *   and safe to share (§13).
 *
 * 2. Specification Grammar
 * ========================
 *
 * ```
 * spec    = { entry }
 * entry   = int    "=>" name        list form: enabled, defaults
 *         | name   "=>" true        flag form: enabled, defaults
 *         | name   "=>" false       flag form: disabled (skipped)
 *         | name   "=>" options     options form: [] means defaults
 * options = { option-name "=>" value }       option-name is a string
 * name    = a processor name registered in the context
 * ```
 *
 * Entries run in the array's iteration order. A name may appear once per
 * spec in the flag and options forms, because PHP array keys are unique
 * (§6.6).
 *
 * 3. Resolution Rules
 * ===================
 *
 * | Key      | Value                        | Result                                  |
 * |----------|------------------------------|-----------------------------------------|
 * | int      | string                       | stage: the registered processor         |
 * | string   | true                         | stage: the registered processor         |
 * | string   | false                        | no stage                                |
 * | string   | [] (empty array)             | stage: the registered processor         |
 * | string   | non-empty array<string, …>   | stage: a clone, configured once         |
 * | string   | array with an int option key | InvalidProcessorConfigurationException  |
 * | any      | anything else                | InvalidProcessorConfigurationException  |
 *
 * 4. ARFA 1.3 Compliance
 * ======================
 *
 * P1 (Immutable State Transformation):
 *   The built Pipeline is immutable, and building never mutates the
 *   registry: configured entries run on clones (ADR-005).
 *
 * P2 (Reactive Flow Composition):
 *   The specification is the declarative form of f₁ ∘ f₂ ∘ ... ∘ fₙ:
 *   iteration order is composition order.
 *
 * P3 (Adaptive Context Awareness) — not implemented:
 *   Configuration is static and applied once at build time; nothing here
 *   adapts at run time from metrics or history.
 *
 * P4 (Protocol Agnosticism):
 *   Specifications are plain arrays: PHP literals, decoded JSON or
 *   attribute arguments (§11) all build the same way.
 *
 * P5 (Continuous Observability):
 *   Every rejection carries structured context (`specKey`, `valueType`,
 *   `processor`, `reason`), so a bad specification is diagnosable from
 *   logs alone (§9).
 *
 * 5. Invariants
 * =============
 *
 * B1  Stage order equals the specification's iteration order, minus the
 *     entries set to false.
 * B2  A build never mutates the registry or any registered instance.
 * B3  A configured stage is a fresh clone on which configure() ran exactly
 *     once, with the given options.
 * B4  An unconfigured stage is the registered instance itself.
 * B5  A build is all-or-nothing: any error aborts it and no partial
 *     pipeline is returned.
 * B6  Building is deterministic: the same registry contents and the same
 *     specification give the same stage classes, order and options.
 *
 * 6. Usage Cookbook
 * =================
 *
 * Examples run in the test suite with the conventions described in
 * Pipeline §6; `$registry` and `$builder` come from the prelude.
 *
 * 6.1 List form
 * -------------
 *
 * ```php
 * $pipeline = $builder->buildPipeline('sanitizer', ['trim', 'lowercase']);
 *
 * assert(count($pipeline) === 2);
 * assert($pipeline->process('  ABC  ') === 'abc');
 * ```
 *
 * 6.2 Flag form, driven by configuration
 * --------------------------------------
 *
 * ```php
 * $normalizeCase = false; // e.g. read from application settings
 *
 * $pipeline = $builder->buildPipeline('sanitizer', [
 *     'trim' => true,
 *     'lowercase' => $normalizeCase,
 * ]);
 *
 * assert(count($pipeline) === 1);
 * assert($pipeline->process(' AbC ') === 'AbC');
 * ```
 *
 * 6.3 Options form
 * ----------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
 *
 * $pipeline = $builder->buildPipeline('validator', [
 *     'required',
 *     'length' => ['minLength' => 3, 'maxLength' => 8],
 * ]);
 *
 * assert($pipeline->process('kariri') === 'kariri');
 *
 * $caught = null;
 * try {
 *     $pipeline->process('ab');
 * } catch (PipelineExecutionException $e) {
 *     $caught = $e;
 * }
 * assert($caught?->getPrevious()?->getMessage() === 'Length must be between 3 and 8.');
 * ```
 *
 * 6.4 Empty options mean defaults
 * -------------------------------
 *
 * ```php
 * // TrimProcessor is not configurable; an empty array is still accepted.
 * $pipeline = $builder->buildPipeline('sanitizer', ['trim' => []]);
 *
 * assert($pipeline->process(' x ') === 'x');
 * ```
 *
 * 6.5 All forms in one specification
 * -----------------------------------
 *
 * ```php
 * $pipeline = $builder->buildPipeline('validator', [
 *     'required',                      // list form
 *     'email' => true,                 // flag form
 *     'length' => ['maxLength' => 64], // options form
 * ]);
 *
 * assert(count($pipeline) === 3);
 * assert($pipeline->process('dev@kariricode.org') === 'dev@kariricode.org');
 * ```
 *
 * 6.6 One processor, two option sets
 * ----------------------------------
 *
 * PHP keeps only the last of duplicate keys in an array literal, so the
 * builder never sees the first one:
 *
 * ```php
 * $spec = ['length' => ['minLength' => 2], 'length' => ['maxLength' => 4]];
 *
 * assert(count($spec) === 1); // the minLength entry is gone before the builder runs
 * ```
 *
 * To apply a processor twice with different options, register it under two
 * names:
 *
 * ```php
 * $registry->register('validator', 'min', new LengthValidator())
 *     ->register('validator', 'max', new LengthValidator());
 *
 * $pipeline = $builder->buildPipeline('validator', [
 *     'min' => ['minLength' => 2],
 *     'max' => ['maxLength' => 4],
 * ]);
 *
 * assert($pipeline->process('abc') === 'abc');
 * ```
 *
 * 6.7 Sanitize, then validate
 * ---------------------------
 *
 * A specification resolves against one context. Combine contexts by
 * concatenating pipelines:
 *
 * ```php
 * $pipeline = $builder->buildPipeline('sanitizer', ['trim', 'lowercase'])
 *     ->withPipeline($builder->buildPipeline('validator', ['required', 'email']));
 *
 * assert($pipeline->process('  Dev@KaririCode.org ') === 'dev@kariricode.org');
 * ```
 *
 * 6.8 Specifications from JSON
 * ----------------------------
 *
 * ```php
 * $json = '{"trim": true, "lowercase": false, "uppercase": true}';
 *
 * $pipeline = $builder->buildPipeline('sanitizer', json_decode($json, true, flags: JSON_THROW_ON_ERROR));
 *
 * assert($pipeline->process(' ab ') === 'AB');
 * ```
 *
 * External specifications can only name registered processors; see
 * ProcessorRegistry §16 for the allowlist this provides.
 *
 * 7. Configuration Isolation
 * ==========================
 *
 * A configured entry runs on `clone $prototype`, configured once (B3). The
 * registry instance is never touched (B2), so the order in which pipelines
 * are built cannot change their behaviour:
 *
 * ```php
 * $short = $builder->buildPipeline('validator', ['length' => ['maxLength' => 3]]);
 * $long = $builder->buildPipeline('validator', ['length' => ['maxLength' => 30]]);
 *
 * assert($long->process('a longer value') === 'a longer value'); // unaffected by $short
 * assert($registry->get('validator', 'length')->process(str_repeat('x', 99)) !== null);
 * ```
 *
 * `clone` is shallow. When configure() writes into a nested object, the
 * clone and the prototype share that object — unless the processor
 * implements `__clone()`:
 *
 * ```php
 * use KaririCode\Contract\Processor\ConfigurableProcessor;
 *
 * final class Limits
 * {
 *     public int $max = PHP_INT_MAX;
 * }
 *
 * final class ShallowLimit implements ConfigurableProcessor
 * {
 *     public Limits $limits;
 *
 *     public function __construct()
 *     {
 *         $this->limits = new Limits();
 *     }
 *
 *     public function configure(array $options): void
 *     {
 *         $this->limits->max = is_int($options['max'] ?? null) ? $options['max'] : $this->limits->max;
 *     }
 *
 *     public function process(mixed $input): mixed
 *     {
 *         return $input;
 *     }
 * }
 *
 * final class DeepLimit implements ConfigurableProcessor
 * {
 *     public Limits $limits;
 *
 *     public function __construct()
 *     {
 *         $this->limits = new Limits();
 *     }
 *
 *     public function __clone()
 *     {
 *         $this->limits = clone $this->limits; // copy nested state
 *     }
 *
 *     public function configure(array $options): void
 *     {
 *         $this->limits->max = is_int($options['max'] ?? null) ? $options['max'] : $this->limits->max;
 *     }
 *
 *     public function process(mixed $input): mixed
 *     {
 *         return $input;
 *     }
 * }
 *
 * $registry->register('validator', 'shallow', $shallow = new ShallowLimit())
 *     ->register('validator', 'deep', $deep = new DeepLimit());
 *
 * $builder->buildPipeline('validator', ['shallow' => ['max' => 2], 'deep' => ['max' => 2]]);
 *
 * assert($shallow->limits->max === 2);        // nested object shared: the prototype changed
 * assert($deep->limits->max === PHP_INT_MAX); // __clone() kept the prototype intact
 * ```
 *
 * 8. Fail-Closed Parsing
 * ======================
 *
 * Every rejected shape below is a realistic mistake that would otherwise
 * remove a processor silently:
 *
 * | Entry                        | Typical cause                           |
 * |------------------------------|-----------------------------------------|
 * | 'required' => 'yes'          | string flag from a form or a .env file  |
 * | 'required' => 1              | int flag; only true and false are flags |
 * | 'required' => null           | a missing configuration value           |
 * | [['minLength' => 3]]         | options written without a name          |
 * | 'length' => [3, 50]          | positional options                      |
 * | 'trim' => ['chars' => ' ']   | options for a non-configurable processor|
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\InvalidProcessorConfigurationException;
 *
 * $rejected = [];
 * foreach ([['required' => 'yes'], ['required' => 1], ['required' => null], [['minLength' => 3]]] as $spec) {
 *     try {
 *         $builder->buildPipeline('validator', $spec);
 *     } catch (InvalidProcessorConfigurationException $e) {
 *         $rejected[] = $e->context;
 *     }
 * }
 *
 * assert($rejected === [
 *     ['specKey' => 'required', 'valueType' => 'string'],
 *     ['specKey' => 'required', 'valueType' => 'int'],
 *     ['specKey' => 'required', 'valueType' => 'null'],
 *     ['specKey' => 0, 'valueType' => 'array'],
 * ]);
 * ```
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\InvalidProcessorConfigurationException;
 *
 * $codes = [];
 * foreach (['validator' => ['length' => [3, 50]], 'sanitizer' => ['trim' => ['chars' => ' ']]] as $context => $spec) {
 *     try {
 *         $builder->buildPipeline($context, $spec);
 *     } catch (InvalidProcessorConfigurationException $e) {
 *         $codes[$e->context['processor']] = $e->getCode();
 *     }
 * }
 *
 * assert($codes['length'] === InvalidProcessorConfigurationException::OPTION_KEY_NOT_A_NAME);
 * assert($codes['trim'] === InvalidProcessorConfigurationException::NOT_CONFIGURABLE);
 * ```
 *
 * 9. Error Semantics
 * ==================
 *
 * | Situation                              | Exception                                                      | Context keys          |
 * |----------------------------------------|----------------------------------------------------------------|-----------------------|
 * | name not registered in the context     | ProcessorNotFoundException::forNameInContext() — 8501          | processor, context    |
 * | entry outside the grammar              | ...::forSpecificationEntry() — 8510                            | specKey, valueType    |
 * | int option key                         | ...::nonStringOptionKey() — 8511                               | processor, optionKey  |
 * | options for a non-configurable one     | ...::notConfigurable() — 8512                                  | processor             |
 * | configure() throws                     | the processor's own exception, unwrapped                       | —                     |
 * | the processor forbids cloning          | PHP's Error from `clone`, unwrapped                            | —                     |
 *
 * All of them abort the build (B5). Entries are checked in iteration
 * order, so the first offending entry is the one reported.
 *
 * 10. Algorithm and Cost
 * ======================
 *
 * ```
 * FOR EACH (key, value) IN spec, in order
 *   (name, options) ← resolve(key, value)     // null → skip; malformed → raise
 *   P ← registry.get(context, name)           // may raise ProcessorNotFoundException
 *   stage ← options = [] ? P : configure(clone P, options)
 *   stages.append(stage)
 * RETURN new Pipeline(stages)
 * ```
 *
 * Time O(s + o), where s = entries and o = total option count, plus one
 * clone and one configure() per configured entry.
 *
 * Measured on PHP 8.4.26 CLI with opcache and JIT disabled (2026-09-29),
 * indicative only:
 *
 * | Specification                    | Cost per build |
 * |----------------------------------|----------------|
 * | 5 names (list form)              | ~3.8–4.0 µs    |
 * | 5 configured entries (clones)    | ~7.1–7.6 µs    |
 *
 * Running the resulting 5-stage pipeline costs about 0.9 µs (Pipeline §12):
 * build once and reuse (§13).
 *
 * 11. Building from #[Process] Attributes
 * =======================================
 *
 * The #[Process] attribute carries a specification in its `processors`
 * argument. This package does not scan attributes; a consumer reads them
 * and calls the builder:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Attribute\Process;
 *
 * final class SignupForm
 * {
 *     #[Process(processors: ['trim', 'lowercase'])]
 *     public string $email = '';
 * }
 *
 * $property = new \ReflectionProperty(SignupForm::class, 'email');
 * $attribute = $property->getAttributes(Process::class)[0]->newInstance();
 *
 * $pipeline = $builder->buildPipeline('sanitizer', $attribute->processors);
 *
 * assert($pipeline->process('  Dev@KaririCode.ORG ') === 'dev@kariricode.org');
 * ```
 *
 * 12. Design Decisions
 * ====================
 *
 * - Fail closed on malformed entries — ADR-003 (amended).
 * - Configure clones, never prototypes — ADR-005.
 * - `[]` means defaults, so a specification can switch a processor on
 *   uniformly (`'name' => []`) whether or not it is configurable.
 * - No caching inside the builder: it stays stateless and deterministic
 *   (B6); callers decide what to cache (§13).
 * - The contract's ProcessorBuilder interface is not implemented: its
 *   buildPipeline() returns the mutable contract Pipeline — ADR-004.
 *
 * 13. Concurrency and Caching Pipelines
 * =====================================
 *
 * The builder is `readonly` and keeps no state between calls, so one
 * instance can serve every request, Fiber or coroutine. Building costs
 * several runs (§10); in a worker, cache pipelines by specification:
 *
 * ```php
 * $cache = [];
 * $pipelineFor = static function (string $context, array $spec) use (&$cache, $builder) {
 *     $key = $context . ':' . json_encode($spec, JSON_THROW_ON_ERROR);
 *
 *     return $cache[$key] ??= $builder->buildPipeline($context, $spec);
 * };
 *
 * $first = $pipelineFor('sanitizer', ['trim']);
 * $second = $pipelineFor('sanitizer', ['trim']);
 *
 * assert($first === $second);
 * ```
 *
 * Such a cache is valid only while the registry does not change, which is
 * the expected state after boot (ProcessorRegistry §10).
 *
 * 14. Anti-Patterns
 * =================
 *
 * - Building inside a hot loop (§10, §13).
 * - Passing 0 and 1 as flags; only true and false are flags (§8).
 * - Positional options (`'length' => [3, 50]`); options are addressed by
 *   name (§8).
 * - Repeating a key to apply a processor twice (§6.6).
 * - Calling configure() yourself on a registered instance: every pipeline
 *   sharing that prototype changes. Put the options in the specification.
 * - Catching InvalidProcessorConfigurationException to "continue with what
 *   is valid": the specification is wrong and should be fixed, not
 *   partially applied (B5).
 *
 * 15. Testing
 * ===========
 *
 * - Build against a real registry filled with small processors.
 * - Assert rejections through the exception context (`specKey`,
 *   `valueType`, `processor`, `reason`), not message text.
 * - To prove isolation, keep a reference to the registered instance and
 *   assert it is unchanged after building (§7).
 * - Build every production specification once in a test or at boot; a
 *   missing name then fails CI instead of the first request
 *   (ProcessorRegistry §15).
 *
 * 16. Migration from V1.x
 * =======================
 *
 * Verified against kariricode/processor-pipeline v1.3.2:
 *
 * | V1.x                                                            | V4.0                                        |
 * |-----------------------------------------------------------------|---------------------------------------------|
 * | Map form only; list entries such as ['required', 'email'] were  | List form supported                         |
 * | skipped, so an all-list specification built an empty pipeline   |                                             |
 * | Entries other than true or an array were skipped silently       | Rejected (§8); false still disables         |
 * | configure() called on the registry's instance                   | Called on a clone (§7)                      |
 * | Options for a non-configurable processor were ignored           | InvalidProcessorConfigurationException      |
 * | build(context, name, config): Processor                         | Removed; use a one-entry specification      |
 * | Returned the contract's mutable Pipeline                        | Returns the immutable Pipeline              |
 *
 * 17. FAQ
 * =======
 *
 * My specification built in V1 and fails in V4. Why?
 *   It contains an entry V1 skipped silently (§8, §16). The processor it
 *   named was never running; decide whether it should.
 *
 * Why is `['trim' => []]` accepted but `['trim' => ['x' => 1]]` rejected?
 *   An empty array means defaults and needs no configure() call; options
 *   require a ConfigurableProcessor.
 *
 * Can I use numeric processor names?
 *   Only in list form. PHP turns the key '2024' into the int 2024, which
 *   reads as a list position:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\InvalidProcessorConfigurationException;
 * use KaririCode\ProcessorPipeline\ProcessorBuilder;
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $numeric = new ProcessorBuilder(new ProcessorRegistry()->register('sanitizer', '2024', new TrimProcessor()));
 *
 * assert(count($numeric->buildPipeline('sanitizer', ['2024'])) === 1); // list form works
 *
 * $caught = null;
 * try {
 *     $numeric->buildPipeline('sanitizer', ['2024' => true]); // key becomes int 2024
 * } catch (InvalidProcessorConfigurationException $e) {
 *     $caught = $e;
 * }
 * assert($caught !== null);
 * ```
 *
 * Is it safe to share one builder?
 *   Yes; it is readonly and stateless (§13).
 *
 * How do I switch a processor off from configuration without losing its
 * options?
 *   Keep the options in your configuration and choose the entry's value:
 *   `'length' => $enabled ? $lengthOptions : false`.
 *
 * 18. Composing Specifications
 * ============================
 *
 * Specifications are arrays, so they compose with array operations — each
 * with different key semantics:
 *
 * | Operation           | List entries (int keys)          | Named entries (string keys) |
 * |---------------------|----------------------------------|-----------------------------|
 * | [...$a, ...$b]      | appended and renumbered          | later value wins            |
 * | array_merge($a, $b) | appended and renumbered          | later value wins            |
 * | $a + $b             | $b's positions present in $a are | earlier value wins          |
 * |                     | silently dropped                 |                             |
 *
 * The union operator is the trap: two list-form fragments share the keys
 * 0, 1, …, so `+` discards entries of the second fragment.
 *
 * ```php
 * $base = ['trim'];
 * $extra = ['lowercase'];
 *
 * assert(count($builder->buildPipeline('sanitizer', [...$base, ...$extra])) === 2);
 * assert(count($builder->buildPipeline('sanitizer', $base + $extra)) === 1); // 'lowercase' lost
 * ```
 *
 * For named entries, spread lets a later fragment override an earlier
 * one — a clean way to layer defaults and overrides:
 *
 * ```php
 * $defaults = ['required' => true, 'length' => ['maxLength' => 255]];
 * $overrides = ['length' => ['maxLength' => 5]];
 *
 * $pipeline = $builder->buildPipeline('validator', [...$defaults, ...$overrides]);
 *
 * assert(count($pipeline) === 2);
 * assert($pipeline->process('short') === 'short');
 * ```
 *
 * Conditional list entries read best as a spread of a conditional fragment:
 *
 * ```php
 * $strict = true;
 *
 * $spec = ['required', ...($strict ? ['email'] : [])];
 *
 * assert(count($builder->buildPipeline('validator', $spec)) === 2);
 * ```
 *
 * For named entries, the flag form (`'email' => $strict`) says the same
 * thing more directly.
 *
 * 19. Option Values Are the Processor's Job
 * =========================================
 *
 * The builder checks the *shape* of options — named, inside an array — and
 * nothing else. Values reach configure() untouched, so each configurable
 * processor must validate its own options, especially when specifications
 * come from JSON or user settings. A configure() that throws aborts the
 * build with its own exception, unwrapped (§9):
 *
 * ```php
 * use KaririCode\Contract\Processor\ConfigurableProcessor;
 *
 * final class StrictMaxLength implements ConfigurableProcessor
 * {
 *     private int $max = PHP_INT_MAX;
 *
 *     public function configure(array $options): void
 *     {
 *         $max = $options['max'] ?? $this->max;
 *         if (!is_int($max) || $max < 0) {
 *             throw new \InvalidArgumentException('Option "max" must be a non-negative int.');
 *         }
 *         $this->max = $max;
 *     }
 *
 *     public function process(mixed $input): mixed
 *     {
 *         return $input;
 *     }
 * }
 *
 * $registry->register('validator', 'strictMax', new StrictMaxLength());
 *
 * $caught = null;
 * try {
 *     $builder->buildPipeline('validator', json_decode('{"strictMax": {"max": "10"}}', true));
 * } catch (\Throwable $e) {
 *     $caught = $e;
 * }
 *
 * assert($caught !== null && $caught::class === \InvalidArgumentException::class);
 * assert($caught->getMessage() === 'Option "max" must be a non-negative int.');
 * ```
 *
 * Throw rather than fall back to a default: a processor that ignores an
 * unusable value hides the misconfiguration — the failure mode that
 * fail-closed parsing (§8) removes at the specification level.
 *
 * 20. Pre-configured Prototypes
 * =============================
 *
 * A registered instance may already be configured. Its clone inherits that
 * state, and the specification's options apply on top — a way to ship
 * named presets:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
 *
 * $username = new LengthValidator();
 * $username->configure(['minLength' => 3]); // the preset, applied once before registration
 * $registry->register('validator', 'username', $username);
 *
 * $pipeline = $builder->buildPipeline('validator', ['username' => ['maxLength' => 8]]);
 *
 * assert($pipeline->process('kariri') === 'kariri');
 *
 * $failures = 0;
 * foreach (['ab', 'far-too-long-name'] as $candidate) {
 *     try {
 *         $pipeline->process($candidate);
 *     } catch (PipelineExecutionException) {
 *         ++$failures;
 *     }
 * }
 * assert($failures === 2); // the preset minimum and the specified maximum both apply
 * ```
 *
 * This is the one legitimate time to call configure() on a registered
 * instance: before registering it, never afterwards (§14).
 *
 * 21. Build Lifecycle
 * ===================
 *
 * ```
 * caller                   ProcessorBuilder                   ProcessorRegistry
 *   │ buildPipeline(ctx, spec)    │                                    │
 *   │────────────────────────────▶│                                    │
 *   │                             │ for each entry, in order:          │
 *   │                             │   resolveSpec(key, value)          │
 *   │                             │     └─ malformed → raise           │
 *   │                             │   get(ctx, name) ─────────────────▶│
 *   │                             │◀──────────────── prototype P ──────│ (or raise)
 *   │                             │   options? clone P, configure once │
 *   │                             │ new Pipeline(stages)               │
 *   │◀──────── Pipeline ──────────│                                    │
 * ```
 *
 * Nothing is cached between calls, and the registry receives only get()
 * calls.
 *
 * @since     1.0.0
 *
 * @see \KaririCode\ProcessorPipeline\ProcessorRegistry      Processor source
 * @see \KaririCode\ProcessorPipeline\Pipeline\Pipeline      Built product
 * @see \KaririCode\Contract\Processor\ConfigurableProcessor Configuration
 */
final readonly class ProcessorBuilder
{
    /**
     * The registry names are resolved against. It is read, never written.
     *
     * The builder keeps a reference, not a snapshot: processors registered
     * after construction are visible to later builds.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\ProcessorBuilder;
     * use KaririCode\ProcessorPipeline\ProcessorRegistry;
     *
     * $registry = new ProcessorRegistry();
     * $builder = new ProcessorBuilder($registry);
     *
     * $registry->register('sanitizer', 'trim', new TrimProcessor()); // after the builder exists
     *
     * assert(count($builder->buildPipeline('sanitizer', ['trim'])) === 1);
     * ```
     */
    public function __construct(
        private ProcessorRegistry $registry,
    ) {}

    /**
     * Build a pipeline from a context and processor specification.
     *
     * Entries are resolved in iteration order (§3); the first malformed
     * entry or unknown name aborts the build and nothing is returned (B5).
     * The registry is only read (B2).
     *
     * ```php
     * $pipeline = $builder->buildPipeline('sanitizer', ['trim', 'uppercase' => true, 'lowercase' => false]);
     *
     * assert($pipeline->process(' ok ') === 'OK');
     * ```
     *
     * All-or-nothing, even when earlier entries were already resolved — and
     * the prototype of an entry that was configured stays untouched (B2, B5):
     *
     * ```php
     * use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
     *
     * $pipeline = null;
     * try {
     *     $pipeline = $builder->buildPipeline('validator', ['length' => ['maxLength' => 1], 'missing']);
     * } catch (ProcessorNotFoundException) {
     * }
     *
     * assert($pipeline === null);
     * assert($registry->get('validator', 'length')->process('untouched prototype') === 'untouched prototype');
     * ```
     *
     * @param string                   $context        Registry context name
     * @param array<int|string, mixed> $processorSpecs Processor specification
     *
     * @return Pipeline Immutable pipeline ready for execution
     *
     * @throws ProcessorNotFoundException             When a processor name is not in the registry
     * @throws InvalidProcessorConfigurationException When an entry is malformed or configuration fails
     */
    public function buildPipeline(string $context, array $processorSpecs): Pipeline
    {
        $processors = [];

        // Keys are iterated separately so each raw value is handed straight
        // to resolveSpec(), the single validation boundary for untrusted specs.
        foreach (array_keys($processorSpecs) as $key) {
            $entry = $this->resolveSpec($key, $processorSpecs[$key]);

            if ($entry === null) {
                continue;
            }

            [$processorName, $configuration] = $entry;
            $processor = $this->registry->get($context, $processorName);

            $processors[] = $configuration === []
                ? $processor
                : $this->configuredCopy($processor, $processorName, $configuration);
        }

        return new Pipeline($processors);
    }

    /**
     * Resolve one specification entry into a processor name and its options.
     *
     * Fails closed: any entry outside the recognized formats is rejected.
     * The grammar is in §2 and the full decision table in §3. An int key
     * requires a string value, so a list position can never carry options.
     *
     * @return array{string, array<string, mixed>}|null Null means "explicitly disabled"
     *
     * @throws InvalidProcessorConfigurationException When the entry matches no recognized format
     */
    private function resolveSpec(int|string $key, mixed $value): ?array
    {
        // Format: ['required', 'email'] → sequential name list
        if (is_int($key)) {
            return is_string($value)
                ? [$value, []]
                : throw InvalidProcessorConfigurationException::forSpecificationEntry($key, $value);
        }

        return match (true) {
            // Format: ['trim' => false] → explicitly disabled
            $value === false => null,
            // Format: ['trim' => true] → enabled with defaults
            $value === true => [$key, []],
            // Format: ['length' => ['minLength' => 3]] → enabled and configured
            is_array($value) => [$key, $this->normalizeOptions($key, $value)],
            default => throw InvalidProcessorConfigurationException::forSpecificationEntry($key, $value),
        };
    }

    /**
     * Verify that every option is addressed by name.
     *
     * Values are passed through untouched: only the processor knows how to
     * interpret them. An int key means positional options, which a
     * processor cannot address reliably, so it is rejected (§8).
     *
     * @param array<mixed> $options Raw options from the specification
     *
     * @return array<string, mixed> Options keyed by option name
     *
     * @throws InvalidProcessorConfigurationException When an option key is not a string
     */
    private function normalizeOptions(string $processorName, array $options): array
    {
        $normalized = [];

        foreach (array_keys($options) as $option) {
            if (!is_string($option)) {
                throw InvalidProcessorConfigurationException::nonStringOptionKey($processorName, $option);
            }

            // Option values are opaque to the builder; only the processor interprets them.
            /** @psalm-suppress MixedAssignment */
            $normalized[$option] = $options[$option];
        }

        return $normalized;
    }

    /**
     * Configure a private copy of a registered processor.
     *
     * The registry instance acts as a prototype and is never mutated, so two
     * pipelines built with different options cannot interfere (ARFA 1.3 P1).
     * The clone is shallow; §7 shows when a processor needs __clone().
     *
     * @param Processor            $processor     Registered prototype
     * @param string               $processorName Name for error reporting
     * @param array<string, mixed> $configuration Non-empty configuration options
     *
     * @throws InvalidProcessorConfigurationException When the processor is not configurable
     */
    private function configuredCopy(
        Processor $processor,
        string $processorName,
        array $configuration,
    ): Processor {
        if (!$processor instanceof ConfigurableProcessor) {
            throw InvalidProcessorConfigurationException::notConfigurable($processorName);
        }

        $copy = clone $processor;
        $copy->configure($configuration);

        return $copy;
    }
}
