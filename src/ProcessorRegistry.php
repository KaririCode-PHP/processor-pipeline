<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline;

use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;

/**
 * Context-namespaced registry of processor prototypes.
 *
 * Contents
 * ========
 *
 *  1. Overview                      9. Design Decisions
 *  2. Core Concepts                10. Concurrency and Long-Running Runtimes
 *  3. ARFA 1.3 Compliance          11. Anti-Patterns
 *  4. Invariants                   12. Testing with a Registry
 *  5. API at a Glance              13. Migration from V1.x
 *  6. Usage Cookbook               14. FAQ
 *  7. Data Structure and Cost      15. Boot-Time Verification and Diagnostics
 *  8. Error Semantics              16. Security: the Registry Is an Allowlist
 *                                 17. Organising Registration at Scale
 *                                 18. Lifecycle
 *
 * 1. Overview
 * ===========
 *
 * The registry maps a (context, name) pair to a processor instance. It is
 * the component's composition root: an application fills it once, at boot,
 * and {@see ProcessorBuilder} reads from it to build pipelines.
 *
 * - Contexts separate domains. 'validator', 'sanitizer' and 'transformer'
 *   can each own a processor called 'trim' without coordinating names.
 * - Registered instances are prototypes. Building never modifies them: a
 *   configured entry receives a configured clone (ADR-005).
 * - Lookups come in three forms — throwing, nullable and boolean — so the
 *   caller states whether a miss is an error.
 *
 * 2. Core Concepts
 * ================
 *
 * Context    A string namespace such as 'validator', 'sanitizer' or
 *            'http.validator'. It is static: chosen at registration, never
 *            inferred at run time.
 * Name       The processor's name within its context. Specifications refer
 *            to processors by this name: `['trim', 'length' => [...]]`.
 * Prototype  The registered instance. Unconfigured pipeline entries share it
 *            by reference; configured entries get a configured clone.
 * Entry      One (context, name, prototype) triple.
 *
 * 3. ARFA 1.3 Compliance
 * ======================
 *
 * P1 (Immutable State Transformation) — scope:
 *   The registry is a composition-root object and is mutable by design:
 *   re-registering a name silently replaces the previous instance
 *   (last-write-wins). What P1 guarantees here is that *building* never
 *   mutates it: {@see ProcessorBuilder} configures a clone, so registered
 *   instances act as untouched prototypes (§6.8).
 *
 * P3 (Adaptive Context Awareness) — not implemented:
 *   The `context` argument is a static namespace (e.g. 'validator'),
 *   not ARFA's adaptive context, which adapts behaviour at run time from
 *   metrics and history. Metrics for such an adapter are available via
 *   {@see \KaririCode\ProcessorPipeline\Observer\PipelineObserver}.
 *
 * P4 (Protocol Agnosticism):
 *   The registry stores Processor instances and nothing else; the same
 *   processor serves HTTP, CLI or message-queue input unchanged.
 *
 * P5 (Continuous Observability):
 *   Every entry is introspectable at run time (§6.4) — useful to log what a
 *   worker booted with.
 *
 * 4. Invariants
 * =============
 *
 * R1  Every stored value is a Processor (enforced by register()'s signature).
 * R2  A (context, name) pair maps to at most one processor; registering it
 *     again replaces the previous one (last-write-wins).
 * R3  No context is empty: remove() drops a context together with its last
 *     entry, so contextNames lists only contexts that hold processors.
 * R4  Building a pipeline never adds, removes, replaces or mutates an entry.
 * R5  count() === totalCount === number of entries across all contexts.
 * R6  Names returned by introspection are strings, even for numeric names
 *     such as '123', which PHP stores as integer array keys (§6.10).
 *
 * 5. API at a Glance
 * ==================
 *
 * | Member                        | Kind   | Returns                  | Throws                     |
 * |-------------------------------|--------|--------------------------|----------------------------|
 * | register($context, $name, $p) | method | $this (fluent)           | —                          |
 * | get($context, $name)          | method | Processor                | ProcessorNotFoundException |
 * | find($context, $name)         | method | ?Processor               | —                          |
 * | has($context, $name)          | method | bool                     | —                          |
 * | getByContext($context)        | method | array<array-key, Processor> | —                       |
 * | getContextNames()             | method | list<string>             | —                          |
 * | getProcessorNames($context)   | method | list<string>             | —                          |
 * | count()                       | method | int (\Countable)         | —                          |
 * | remove($context, $name)       | method | bool                     | —                          |
 * | clear()                       | method | void                     | —                          |
 * | $contextNames                 | hook   | list<string>             | —                          |
 * | $totalCount                   | hook   | int                      | —                          |
 * | $isEmpty                      | hook   | bool                     | —                          |
 *
 * 6. Usage Cookbook
 * =================
 *
 * Examples run in the test suite with the conventions described in
 * Pipeline §6. They build their own registry instead of using the
 * prelude's `$registry`.
 *
 * 6.1 Register and build
 * ----------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\ProcessorBuilder;
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $registry = new ProcessorRegistry()
 *     ->register('sanitizer', 'trim', new TrimProcessor())
 *     ->register('sanitizer', 'lowercase', new LowercaseProcessor());
 *
 * $pipeline = new ProcessorBuilder($registry)->buildPipeline('sanitizer', ['trim', 'lowercase']);
 *
 * assert($pipeline->process('  ABC ') === 'abc');
 * ```
 *
 * 6.2 Same name, different contexts
 * ---------------------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $registry = new ProcessorRegistry()
 *     ->register('sanitizer', 'case', new LowercaseProcessor())
 *     ->register('formatter', 'case', new UpperCaseProcessor());
 *
 * assert($registry->get('sanitizer', 'case')->process('Ab') === 'ab');
 * assert($registry->get('formatter', 'case')->process('Ab') === 'AB');
 * ```
 *
 * 6.3 Choose the lookup that states your intent
 * ---------------------------------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $registry = new ProcessorRegistry()->register('validator', 'required', new RequiredValidator());
 *
 * // A miss is a bug: get() throws, with structured context.
 * $caught = null;
 * try {
 *     $registry->get('validator', 'emial');
 * } catch (ProcessorNotFoundException $e) {
 *     $caught = $e;
 * }
 * assert($caught !== null);
 * assert($caught->context === ['processor' => 'emial', 'context' => 'validator']);
 *
 * // A miss is expected: find() returns null.
 * $email = $registry->find('validator', 'email') ?? new EmailValidator();
 * assert($email instanceof EmailValidator);
 *
 * // Only existence matters: has().
 * assert($registry->has('validator', 'required'));
 * assert(!$registry->has('validator', 'email'));
 * ```
 *
 * 6.4 Introspect what is registered
 * ---------------------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $registry = new ProcessorRegistry()
 *     ->register('sanitizer', 'trim', new TrimProcessor())
 *     ->register('sanitizer', 'lowercase', new LowercaseProcessor())
 *     ->register('validator', 'required', new RequiredValidator());
 *
 * assert($registry->contextNames === ['sanitizer', 'validator']);
 * assert($registry->getProcessorNames('sanitizer') === ['trim', 'lowercase']);
 * assert(count($registry) === 3 && $registry->totalCount === 3);
 * assert(array_keys($registry->getByContext('validator')) === ['required']);
 * assert($registry->getByContext('unknown') === []);
 * ```
 *
 * Names are listed in registration order.
 *
 * 6.5 Swap an implementation (tests, environments)
 * ------------------------------------------------
 *
 * ```php
 * use KaririCode\Contract\Processor\Processor;
 * use KaririCode\ProcessorPipeline\ProcessorBuilder;
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * final class AcceptAnything implements Processor
 * {
 *     public function process(mixed $input): mixed
 *     {
 *         return $input;
 *     }
 * }
 *
 * $registry = new ProcessorRegistry()->register('validator', 'email', new EmailValidator());
 * $registry->register('validator', 'email', new AcceptAnything()); // last write wins
 *
 * $pipeline = new ProcessorBuilder($registry)->buildPipeline('validator', ['email']);
 *
 * assert($pipeline->process('not-an-email') === 'not-an-email');
 * assert(count($registry) === 1);
 * ```
 *
 * 6.6 Detect accidental overwrites at boot
 * ----------------------------------------
 *
 * Last-write-wins is convenient in tests and dangerous in bootstrap code,
 * where a copy-pasted line can silently replace a validator. Guard it where
 * registration is assembled:
 *
 * ```php
 * use KaririCode\Contract\Processor\Processor;
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * function registerOnce(ProcessorRegistry $registry, string $context, string $name, Processor $processor): void
 * {
 *     if ($registry->has($context, $name)) {
 *         throw new \LogicException("Processor '{$context}.{$name}' is already registered.");
 *     }
 *
 *     $registry->register($context, $name, $processor);
 * }
 *
 * $registry = new ProcessorRegistry();
 * registerOnce($registry, 'sanitizer', 'trim', new TrimProcessor());
 *
 * $caught = null;
 * try {
 *     registerOnce($registry, 'sanitizer', 'trim', new LowercaseProcessor());
 * } catch (\LogicException $e) {
 *     $caught = $e;
 * }
 * assert($caught !== null);
 * ```
 *
 * 6.7 Bootstrap from configuration
 * --------------------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * // Typically loaded from a config file or resolved from a PSR-11 container.
 * $definitions = [
 *     'sanitizer' => ['trim' => TrimProcessor::class, 'lowercase' => LowercaseProcessor::class],
 *     'validator' => ['required' => RequiredValidator::class, 'email' => EmailValidator::class],
 * ];
 *
 * $registry = new ProcessorRegistry();
 * foreach ($definitions as $context => $processors) {
 *     foreach ($processors as $name => $class) {
 *         $registry->register($context, $name, new $class());
 *     }
 * }
 *
 * assert($registry->totalCount === 4);
 * ```
 *
 * With a container, replace `new $class()` by `$container->get($class)`;
 * the registry itself stays container-agnostic.
 *
 * 6.8 Prototypes stay untouched by builds
 * ---------------------------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\ProcessorBuilder;
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $registry = new ProcessorRegistry()
 *     ->register('validator', 'length', new LengthValidator())
 *     ->register('sanitizer', 'trim', new TrimProcessor());
 * $builder = new ProcessorBuilder($registry);
 *
 * $strict = $builder->buildPipeline('validator', ['length' => ['maxLength' => 3]]);
 *
 * // The prototype keeps its defaults: the build configured a clone.
 * assert($registry->get('validator', 'length')->process('a long value') === 'a long value');
 * assert($strict->getProcessors()[0] !== $registry->get('validator', 'length'));
 *
 * // Unconfigured entries share the prototype by reference.
 * $plain = $builder->buildPipeline('sanitizer', ['trim']);
 * assert($plain->getProcessors()[0] === $registry->get('sanitizer', 'trim'));
 * ```
 *
 * 6.9 Remove and clear
 * --------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\ProcessorBuilder;
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $registry = new ProcessorRegistry()
 *     ->register('sanitizer', 'trim', new TrimProcessor())
 *     ->register('validator', 'required', new RequiredValidator());
 * $pipeline = new ProcessorBuilder($registry)->buildPipeline('sanitizer', ['trim']);
 *
 * assert($registry->remove('sanitizer', 'trim') === true);
 * assert($registry->remove('sanitizer', 'trim') === false); // already gone
 * assert($registry->contextNames === ['validator']);        // empty context dropped (R3)
 *
 * $registry->clear();
 * assert($registry->isEmpty);
 *
 * // Pipelines built earlier hold their own references.
 * assert($pipeline->process(' x ') === 'x');
 * ```
 *
 * 6.10 Numeric names
 * ------------------
 *
 * PHP stores a numeric-string array key such as '123' as the integer 123.
 * Introspection converts keys back to strings (R6), so names read from the
 * registry can be passed straight back to it under strict_types.
 * getByContext() returns the raw array and can therefore contain int keys.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $registry = new ProcessorRegistry()->register('2024', '1', new TrimProcessor());
 *
 * assert($registry->getContextNames() === ['2024']);
 * assert($registry->getProcessorNames('2024') === ['1']);
 * assert(array_keys($registry->getByContext('2024')) === [1]); // raw PHP array key
 *
 * foreach ($registry->getContextNames() as $context) {
 *     foreach ($registry->getProcessorNames($context) as $name) {
 *         assert($registry->has($context, $name));
 *     }
 * }
 * ```
 *
 * 7. Data Structure and Cost
 * ==========================
 *
 * Storage is a two-level PHP array, context → name → Processor: two hash
 * lookups per access, no objects allocated per entry.
 *
 * | Operation                     | Time | Extra space |
 * |-------------------------------|------|-------------|
 * | register(), find(), get()     | O(1) | O(1)        |
 * | has(), remove()               | O(1) | O(1)        |
 * | getByContext()                | O(1) | O(1)*       |
 * | getContextNames()             | O(c) | O(c)        |
 * | getProcessorNames()           | O(k) | O(k)        |
 * | count(), totalCount           | O(c) | O(1)        |
 * | clear()                       | O(1) | O(1)        |
 *
 * c = number of contexts, k = processors in the context.
 * (*) arrays are copy-on-write: the returned array is only copied if the
 *     caller modifies it.
 *
 * Measured on PHP 8.4.26 CLI, opcache and JIT disabled (2026-09-29): a
 * find() hit costs roughly 0.2 µs including the call. Indicative only.
 *
 * 8. Error Semantics
 * ==================
 *
 * | Method              | On a missing entry                         |
 * |---------------------|--------------------------------------------|
 * | get()               | throws ProcessorNotFoundException          |
 * | find()              | returns null                               |
 * | has()               | returns false                              |
 * | getByContext()      | returns []                                 |
 * | getProcessorNames() | returns []                                 |
 * | remove()            | returns false                              |
 *
 * ProcessorNotFoundException has code 8501 (`NOT_REGISTERED`), implements
 * the contract's `Exception\NotFound`, and carries `context: ['processor' =>
 * $name, 'context' => $context]`. Its message does not repeat the name,
 * which may come from an external specification (§16).
 *
 * register() never throws: re-registering replaces (R2). clear() and the
 * hooks never throw.
 *
 * 9. Design Decisions
 * ===================
 *
 * - Two-level map rather than prefixed names ('validator.trim'): typos in a
 *   prefix cannot silently create a new namespace — ADR-002.
 * - Last-write-wins: test doubles and environment overrides need it; guard
 *   bootstrap code when you do not (§6.6).
 * - Instances, not factories: registration stays a one-liner; isolation of
 *   configured entries comes from cloning at build time — ADR-005.
 * - No lazy loading: resolve lazily in your container, then register.
 * - The contract's ProcessorRegistry interface is not implemented, because
 *   its getContextProcessors() must return a kariricode/data-structure Map;
 *   getByContext() returns a plain array instead — ADR-004.
 * - Hooks and methods: the property hooks read naturally in conditions
 *   (`if ($registry->isEmpty)`), while the methods work as callables
 *   (`$registry->count(...)`). Both read the same data.
 *
 * 10. Concurrency and Long-Running Runtimes
 * =========================================
 *
 * Build the registry at boot and treat it as read-only afterwards. Reads
 * are safe to share between requests, Fibers and coroutines. Mutating the
 * registry while serving requests affects future builds only — pipelines
 * already built keep their own references (§6.9) — but it makes behaviour
 * depend on request order and should be avoided.
 *
 * 11. Anti-Patterns
 * =================
 *
 * - Registering during request handling (§10).
 * - Registering one stateful processor under several names or contexts
 *   and running them concurrently: they share the instance.
 * - Calling configure() yourself on a registered instance. Every pipeline
 *   sharing that prototype changes; pass options in the specification
 *   instead, so the builder configures a clone.
 * - Using contexts as a feature-flag mechanism ('validator.v2'). Register
 *   the implementation you want under the name you use (§6.5).
 * - Iterating getByContext() keys and passing them to get() under
 *   strict_types: numeric names come back as int (§6.10). Use
 *   getProcessorNames().
 *
 * 12. Testing with a Registry
 * ===========================
 *
 * - Use a real registry; it is cheap and has no dependencies.
 * - Swap real processors for stubs with register() (§6.5).
 * - Assert a missing entry with `expectException(ProcessorNotFoundException::class)`
 *   or through find(), depending on the contract the code under test
 *   promises.
 *
 * 13. Migration from V1.x
 * =======================
 *
 * | V1.x                                           | V4.0                                         |
 * |------------------------------------------------|----------------------------------------------|
 * | register(context, name, processor)             | unchanged, including parameter names         |
 * | get(context, name)                             | unchanged, including parameter names         |
 * | getContextProcessors(context): Map             | getByContext(context): array                 |
 * | implements the contract's ProcessorRegistry    | does not (ADR-004)                           |
 * | —                                              | find(), has(), remove(), clear(), hooks      |
 *
 * 14. FAQ
 * =======
 *
 * Why does registering the same name twice not fail?
 *   Last-write-wins is deliberate (§9). Use the guard in §6.6 where
 *   duplicates indicate a mistake.
 *
 * Can one processor instance be registered under several names?
 *   Yes. They then share the instance, which matters only if the processor
 *   keeps state between calls (§11).
 *
 * Does clear() break pipelines that are already built?
 *   No. Pipelines hold references to their processors (§6.9).
 *
 * Why is getByContext() typed array<array-key, Processor>?
 *   Because PHP turns numeric-string keys into integers (§6.10); the type
 *   states what the array really contains.
 *
 * Are contexts hierarchical?
 *   No. 'http.validator' is a flat string; nothing relates it to 'http' or
 *   to 'validator' (§17).
 *
 * How do I learn at boot whether my specifications are valid?
 *   Build each one once (§15).
 *
 * Can untrusted input choose which processors run?
 *   Only among the registered ones — which is why the registry must be
 *   curated (§16).
 *
 * 15. Boot-Time Verification and Diagnostics
 * ==========================================
 *
 * A specification that names an unregistered processor fails with
 * ProcessorNotFoundException at the first build that uses it. In a
 * long-running worker, that can be the first request after a deploy.
 * Building every known specification once at boot moves the failure to
 * deployment time:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
 *
 * $specifications = [
 *     'signup.email' => ['sanitizer', ['trim', 'lowercase']],
 *     'signup.name'  => ['sanitizer', ['trim', 'titlecase']], // never registered
 * ];
 *
 * $failures = [];
 * foreach ($specifications as $field => [$context, $spec]) {
 *     try {
 *         $builder->buildPipeline($context, $spec);
 *     } catch (ProcessorNotFoundException $e) {
 *         $failures[$field] = $e->context;
 *     }
 * }
 *
 * assert($failures === ['signup.name' => ['processor' => 'titlecase', 'context' => 'sanitizer']]);
 * ```
 *
 * For logs and debug endpoints, summarise the registry by class name
 * instead of exposing the instances:
 *
 * ```php
 * $summary = [];
 * foreach ($registry->getContextNames() as $context) {
 *     foreach ($registry->getProcessorNames($context) as $name) {
 *         $summary[$context][$name] = get_class($registry->get($context, $name));
 *     }
 * }
 *
 * assert(array_keys($summary) === ['sanitizer', 'validator']);
 * assert($summary['sanitizer']['trim'] === TrimProcessor::class);
 * ```
 *
 * 16. Security: the Registry Is an Allowlist
 * ==========================================
 *
 * A specification can only reference registered processors, and the
 * builder rejects every malformed entry. When specifications come from
 * outside the code — an admin UI, a JSON rule set, a tenant's settings —
 * the registry is therefore the complete list of what that input can run:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
 *
 * $untrusted = json_decode('["trim", "shell_exec"]', true);
 *
 * $caught = null;
 * try {
 *     $builder->buildPipeline('sanitizer', $untrusted);
 * } catch (ProcessorNotFoundException $e) {
 *     $caught = $e;
 * }
 * assert($caught !== null && $caught->context['processor'] === 'shell_exec');
 * ```
 *
 * Two rules preserve that property:
 *
 * - Never register processors from class names that untrusted input
 *   supplies. §6.7 reads class names from *your* configuration; taking
 *   them from requests turns the allowlist into arbitrary instantiation.
 * - Register in a context only processors that are safe for any input
 *   the specifications of that context may receive: every specification
 *   naming the context can invoke every processor in it.
 *
 * 17. Organising Registration at Scale
 * ====================================
 *
 * Small applications register inline. When modules or packages contribute
 * processors, give each one a registrar — a callable that receives the
 * registry — and apply them at boot. Registration order then follows
 * module order, and last-write-wins lets the application override a
 * package default simply by registering after it.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\ProcessorRegistry;
 *
 * $packageRegistrar = static function (ProcessorRegistry $registry): void {
 *     $registry->register('sanitizer', 'trim', new TrimProcessor())
 *         ->register('validator', 'email', new EmailValidator());
 * };
 *
 * $appRegistrar = static function (ProcessorRegistry $registry): void {
 *     $registry->register('validator', 'email', new RequiredValidator()); // app override
 * };
 *
 * $registry = new ProcessorRegistry();
 * foreach ([$packageRegistrar, $appRegistrar] as $registrar) {
 *     $registrar($registry);
 * }
 *
 * assert($registry->get('validator', 'email') instanceof RequiredValidator);
 * assert($registry->totalCount === 2);
 * ```
 *
 * Naming conventions that age well:
 *
 * - Contexts are domain nouns: 'validator', 'sanitizer', 'transformer'.
 *   Qualify only when behaviour really differs, as in 'http.validator'.
 *   Contexts are flat strings with no hierarchy.
 * - Names describe the operation, not the class: 'email', not
 *   'EmailValidatorV2'. Replacing the implementation then needs no
 *   specification change (§6.5).
 * - Packages prefix their contexts ('acme.validator'), so two packages can
 *   each ship an 'email' processor without colliding.
 * - Avoid numeric names. They work (§6.10) but read poorly in
 *   specifications.
 *
 * 18. Lifecycle
 * =============
 *
 * ```
 * boot ──▶ register()* ──▶ verify specs (§15) ──▶ build pipelines ──▶ serve
 *            │                                          │                │
 *            │ last-write-wins                          │ get() per name │ process()
 *            ▼                                          ▼                ▼
 *   context → name → prototype               clones for configured   the registry
 *                                            entries (ADR-005)       is not read
 * ```
 *
 * While serving, pipelines never read the registry: every lookup happens at
 * build time. That is why mutating a registry after boot affects only the
 * pipelines built afterwards (§10).
 *
 * @since     1.0.0
 *
 * @see \KaririCode\ProcessorPipeline\ProcessorBuilder      Pipeline construction
 * @see \KaririCode\ProcessorPipeline\Pipeline\Pipeline      Execution engine
 * @see \KaririCode\Contract\Processor\Processor             Processor contract
 * @see \KaririCode\Contract\Processor\ConfigurableProcessor Configurable contract
 */
final class ProcessorRegistry implements \Countable
{
    /**
     * Two-level map: context → name → Processor.
     *
     * Keys are declared array-key, not string: PHP stores a numeric-string
     * key such as '123' as the int 123. Introspection methods convert keys
     * back to strings before returning them.
     *
     * @var array<array-key, array<array-key, Processor>>
     */
    private array $processors = [];

    // ── Property Hooks (PHP 8.4) ─────────────────────────────────────────
    /**
     * All context names, in registration order (computed on read).
     *
     * Same value as {@see getContextNames()}; names are always strings (R6).
     *
     * @var list<string>
     */
    public array $contextNames {
        get => $this->getContextNames();
    }

    /**
     * Number of entries across all contexts (computed on read, O(c)).
     *
     * Same value as {@see count()}.
     */
    public int $totalCount {
        get {
            $total = 0;
            foreach ($this->processors as $contextProcessors) {
                $total += count($contextProcessors);
            }
            return $total;
        }
    }

    /**
     * Whether no processor is registered (computed on read, O(1)).
     *
     * ```php
     * use KaririCode\ProcessorPipeline\ProcessorRegistry;
     *
     * $registry = new ProcessorRegistry();
     * assert($registry->isEmpty);
     *
     * $registry->register('sanitizer', 'trim', new TrimProcessor());
     * assert(!$registry->isEmpty);
     * ```
     */
    public bool $isEmpty {
        get => $this->processors === [];
    }

    // ── Registration ─────────────────────────────────────────────────────
    /**
     * Register a processor under a context and name.
     *
     * Replaces any processor already registered under the same pair
     * (last-write-wins, R2). The instance becomes a prototype: the builder
     * shares it with unconfigured entries and clones it for configured ones.
     *
     * Parameter names match V1.x and kariricode/contract, so named-argument
     * calls such as `register(context: 'validator', name: 'email', processor: $p)`
     * keep working.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\ProcessorRegistry;
     *
     * $registry = new ProcessorRegistry()
     *     ->register(context: 'validator', name: 'email', processor: new EmailValidator())
     *     ->register('validator', 'required', new RequiredValidator());
     *
     * assert($registry->getProcessorNames('validator') === ['email', 'required']);
     * ```
     *
     * @param string    $context   Domain context (e.g. 'validator', 'sanitizer')
     * @param string    $name      Name within the context
     * @param Processor $processor Processor instance (prototype)
     *
     * @return self Fluent interface
     */
    public function register(string $context, string $name, Processor $processor): self
    {
        $this->processors[$context][$name] = $processor;

        return $this;
    }

    // ── Retrieval ────────────────────────────────────────────────────────
    /**
     * Find a processor by context and name, or null when absent.
     *
     * Use this when a missing processor is an expected, recoverable
     * condition. For fail-fast semantics, use {@see get()} instead.
     * `get()` and `has()` are implemented on top of this method, so each
     * lookup costs one nested hash access.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\ProcessorRegistry;
     *
     * $registry = new ProcessorRegistry()->register('sanitizer', 'trim', new TrimProcessor());
     *
     * assert($registry->find('sanitizer', 'trim') instanceof TrimProcessor);
     * assert($registry->find('sanitizer', 'missing') === null);
     * assert($registry->find('missing', 'trim') === null);
     * ```
     */
    public function find(string $context, string $name): ?Processor
    {
        return $this->processors[$context][$name] ?? null;
    }

    /**
     * Retrieve a processor by context and name; a miss is an error.
     *
     * This is the lookup the builder uses: a specification naming an
     * unregistered processor is a configuration bug and must fail loudly.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\Exception\ProcessorNotFoundException;
     * use KaririCode\ProcessorPipeline\ProcessorRegistry;
     *
     * $caught = null;
     * try {
     *     new ProcessorRegistry()->get('validator', 'email');
     * } catch (ProcessorNotFoundException $e) {
     *     $caught = $e;
     * }
     * assert($caught?->getCode() === ProcessorNotFoundException::NOT_REGISTERED);
     * assert($caught->context === ['processor' => 'email', 'context' => 'validator']);
     * assert(!str_contains($caught->getMessage(), 'email'));
     * ```
     *
     * @throws ProcessorNotFoundException When processor is not registered
     */
    public function get(string $context, string $name): Processor
    {
        return $this->find($context, $name)
            ?? throw ProcessorNotFoundException::forNameInContext($name, $context);
    }

    /**
     * Check whether a processor is registered under the pair.
     *
     * Never throws. Prefer {@see find()} when you also need the instance:
     * `has()` followed by `get()` performs the lookup twice.
     *
     * ```php
     * assert($registry->has('sanitizer', 'trim'));
     * assert(!$registry->has('sanitizer', 'email')); // registered, but in 'validator'
     * ```
     */
    public function has(string $context, string $name): bool
    {
        return $this->find($context, $name) !== null;
    }

    /**
     * Every processor registered under a context, keyed by name.
     *
     * Returns [] for an unknown context. The array is the raw storage, so a
     * numeric name appears as an int key (§6.10); use
     * {@see getProcessorNames()} when you need names as strings.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
     *
     * // Every sanitizer, in registration order: trim, lowercase, uppercase.
     * $everySanitizer = new Pipeline($registry->getByContext('sanitizer'));
     *
     * assert(count($everySanitizer) === 3);
     * assert($everySanitizer->process(' Ab ') === 'AB');
     * ```
     *
     * @return array<array-key, Processor>
     */
    public function getByContext(string $context): array
    {
        return $this->processors[$context] ?? [];
    }

    // ── Introspection ────────────────────────────────────────────────────
    /**
     * All context names, in registration order, as strings.
     *
     * The order is that of each context's first registration, not
     * alphabetical.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\ProcessorRegistry;
     *
     * $registry = new ProcessorRegistry()
     *     ->register('validator', 'email', new EmailValidator())
     *     ->register('sanitizer', 'trim', new TrimProcessor())
     *     ->register('validator', 'required', new RequiredValidator());
     *
     * assert($registry->getContextNames() === ['validator', 'sanitizer']);
     * ```
     *
     * @return list<string>
     */
    public function getContextNames(): array
    {
        return array_map(strval(...), array_keys($this->processors));
    }

    /**
     * All processor names within a context, in registration order, as
     * strings. Returns [] for an unknown context.
     *
     * ```php
     * assert($registry->getProcessorNames('validator') === ['required', 'email', 'length']);
     * assert($registry->getProcessorNames('unknown') === []);
     * ```
     *
     * @return list<string>
     */
    public function getProcessorNames(string $context): array
    {
        if (!isset($this->processors[$context])) {
            return [];
        }

        return array_map(strval(...), array_keys($this->processors[$context]));
    }

    /**
     * Number of entries across all contexts; makes `count($registry)` work.
     */
    #[\Override]
    public function count(): int
    {
        $total = 0;

        foreach ($this->processors as $contextProcessors) {
            $total += count($contextProcessors);
        }

        return $total;
    }

    // ── Mutation ─────────────────────────────────────────────────────────
    /**
     * Remove one entry; drops the context when it becomes empty (R3).
     *
     * Pipelines already built are unaffected: they hold their own
     * references to their processors.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\ProcessorRegistry;
     *
     * $registry = new ProcessorRegistry()->register('sanitizer', 'trim', new TrimProcessor());
     *
     * assert($registry->remove(context: 'sanitizer', name: 'trim'));
     * assert($registry->contextNames === []); // context dropped with its last entry
     * assert(!$registry->remove('sanitizer', 'trim'));
     * ```
     *
     * @return bool True if the processor existed and was removed
     */
    public function remove(string $context, string $name): bool
    {
        if (!$this->has($context, $name)) {
            return false;
        }

        unset($this->processors[$context][$name]);

        // Clean up empty contexts
        if ($this->processors[$context] === []) {
            unset($this->processors[$context]);
        }

        return true;
    }

    /**
     * Remove every entry. Pipelines already built are unaffected.
     */
    public function clear(): void
    {
        $this->processors = [];
    }
}
