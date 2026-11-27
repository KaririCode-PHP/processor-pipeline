<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Pipeline;

use KaririCode\Contract\Processor\Processor;
use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;
use KaririCode\ProcessorPipeline\Handler\StageName;
use KaririCode\ProcessorPipeline\Observer\PipelineObserver;

/**
 * Immutable, observable, sequential processor pipeline.
 *
 * Contents
 * ========
 *
 *  1. Overview                     10. Observability Channels
 *  2. Core Concepts                11. Formal Properties
 *  3. ARFA 1.3 Compliance          12. Complexity and Measured Cost
 *  4. Invariants                   13. Concurrency and Long-Running Runtimes
 *  5. API at a Glance              14. Design Decisions
 *  6. Usage Cookbook               15. Anti-Patterns
 *  7. Execution Algorithm          16. Testing Code That Uses a Pipeline
 *  8. Worked Traces                17. Migration from V1.x
 *  9. Error Semantics              18. FAQ
 *                                 19. Writing Processors That Compose Well
 *
 * 1. Overview
 * ===========
 *
 * A Pipeline holds an ordered list of processors and feeds a value through
 * them: the output of stage i becomes the input of stage i + 1. It is the
 * execution engine of this component. {@see \KaririCode\ProcessorPipeline\ProcessorRegistry}
 * stores processors by context and name, and
 * {@see \KaririCode\ProcessorPipeline\ProcessorBuilder} turns a declarative
 * specification into a Pipeline.
 *
 * Three properties define the class:
 *
 * - Immutable. Every `with*()` method returns a new instance. Nothing can
 *   change the processors, their order or the observer of an existing one.
 * - Fail-fast. The first stage that throws stops the run. The failure is
 *   raised as {@see PipelineExecutionException}, carrying the stage index,
 *   the processor that failed, and the original throwable as `previous`.
 * - Observable. An optional {@see PipelineObserver} receives one
 *   notification per executed stage, with its duration in nanoseconds.
 *
 * The pipeline never swallows a failure. For "record every error and keep
 * going" semantics, wrap processors in
 * {@see \KaririCode\ProcessorPipeline\Handler\ProcessorHandler} (§6.10).
 *
 * 2. Core Concepts
 * ================
 *
 * Stage        One position in the pipeline, identified by its zero-based
 *              index. The same processor instance may occupy several stages.
 * State        The value travelling through the pipeline. The Processor
 *              contract types it as `mixed`; the pipeline never inspects it.
 * Processor    Any {@see Processor}: `process(mixed $input): mixed`.
 * Decorator    A processor that wraps another and exposes it through
 *              {@see ProcessorDecorator::innerProcessor()}.
 * Stage name   The fully-qualified class name of the innermost processor of
 *              a stage, found by looking through decorators. Exceptions,
 *              observers, traces and error collections all use this name.
 * Observer     A {@see PipelineObserver} notified after each executed stage.
 *
 * 3. ARFA 1.3 Compliance
 * ======================
 *
 * P1 (Immutable State Transformation):
 *   The pipeline is immutable after construction: {@see withProcessor()},
 *   {@see withPipeline()} and {@see withObserver()} return new instances.
 *   Immutability of the *processed data* depends on its type — scalars and
 *   arrays are copied by value, while objects are only as immutable as their
 *   classes. Processors must return new objects instead of mutating their
 *   input; the pipeline cannot enforce this for `mixed` data.
 *
 * P2 (Reactive Flow Composition):
 *   Processors compose as f₁ ∘ f₂ ∘ ... ∘ fₙ, and composition is associative
 *   with the empty pipeline as identity (§11). Execution is synchronous: the
 *   specification's asynchronous form fᵢ: State → Promise<State> is not
 *   implemented here and belongs to KaririCode\Async.
 *
 * P3 (Adaptive Context Awareness) — not implemented:
 *   Nothing adapts at run time. The observer (§10) supplies the per-stage
 *   metrics an external adaptive context would consume.
 *
 * P4 (Protocol Agnosticism):
 *   Input and output are `mixed`; nothing here knows about HTTP, CLI, gRPC
 *   or any transport, and the observer interface names no vendor.
 *
 * P5 (Continuous Observability):
 *   - trace: failures raise {@see PipelineExecutionException} carrying the
 *     stage index and the stage name; a failure raised by a nested pipeline
 *     is chained, so both the outer and inner stages survive.
 *   - metrics: an optional {@see PipelineObserver} receives the duration of
 *     every executed stage, measured with the monotonic `hrtime()` clock.
 *
 * 4. Invariants
 * =============
 *
 * I1  `getProcessors()` is a list: its keys are exactly 0 … count() − 1,
 *     whatever keys were passed to the constructor.
 * I2  No method of an existing instance changes its processors, their order
 *     or its observer (the class is `readonly`).
 * I3  `process()` calls the processors in index order, each stage at most
 *     once per run, and stops at the first stage that throws.
 * I4  A throwable escaping a stage leaves `process()` wrapped in exactly one
 *     PipelineExecutionException naming that stage, with the throwable as
 *     its `getPrevious()`. The exceptions are listed in §9.
 * I5  With an observer attached, each executed stage triggers exactly one
 *     observer call; stages that do not run trigger none.
 * I6  Without an observer, the clock is never read.
 *
 * 5. API at a Glance
 * ==================
 *
 * | Method                          | Returns          | Cost     |
 * |---------------------------------|------------------|----------|
 * | __construct(array, ?observer)   | —                | O(n)     |
 * | process(mixed)                  | mixed            | O(n·p)   |
 * | withProcessor(Processor)        | new Pipeline     | O(n)     |
 * | withPipeline(Pipeline)          | new Pipeline     | O(n + m) |
 * | withObserver(?PipelineObserver) | new Pipeline     | O(n)     |
 * | getProcessors()                 | list<Processor>  | O(1)     |
 * | count(), isEmpty()              | int, bool        | O(1)     |
 *
 * n = stages in this pipeline, m = stages in the other pipeline,
 * p = average cost of one processor.
 *
 * 6. Usage Cookbook
 * =================
 *
 * Every ```php example in this component's docblocks is executed by
 * tests/Documentation/DocblockExamplesTest.php. The example processors
 * (TrimProcessor, LowercaseProcessor, UpperCaseProcessor, RequiredValidator,
 * EmailValidator, LengthValidator, NestedPipelineProcessor), the StageLog
 * observer and the `$registry` / `$builder` variables come from
 * tests/Documentation/prelude.php. Each `assert()` states the outcome and
 * fails the suite if the behaviour changes.
 *
 * 6.1 Build from a specification (preferred)
 * ------------------------------------------
 *
 * ```php
 * $pipeline = $builder->buildPipeline('sanitizer', ['trim', 'lowercase']);
 *
 * assert($pipeline->process('  HeLLo  ') === 'hello');
 * ```
 *
 * 6.2 Construct directly
 * ----------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 *
 * $pipeline = new Pipeline([new TrimProcessor(), new UpperCaseProcessor()]);
 *
 * assert($pipeline->process('  mixed Case ') === 'MIXED CASE');
 * ```
 *
 * 6.3 Constructor keys are discarded
 * ----------------------------------
 *
 * The array's iteration order defines the stage order; its keys do not.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 *
 * $pipeline = new Pipeline(['first' => new TrimProcessor(), 7 => new LowercaseProcessor()]);
 *
 * assert(array_keys($pipeline->getProcessors()) === [0, 1]);
 * assert($pipeline->process(' AB ') === 'ab');
 * ```
 *
 * 6.4 Branch variants from a shared base
 * --------------------------------------
 *
 * ```php
 * $base = $builder->buildPipeline('sanitizer', ['trim']);
 *
 * $lower = $base->withProcessor(new LowercaseProcessor());
 * $upper = $base->withProcessor(new UpperCaseProcessor());
 *
 * assert($base->process(' Ab ') === 'Ab');
 * assert($lower->process(' Ab ') === 'ab');
 * assert($upper->process(' Ab ') === 'AB');
 * assert(count($base) === 1 && count($lower) === 2);
 * ```
 *
 * 6.5 Concatenate pipelines
 * -------------------------
 *
 * ```php
 * $sanitize = $builder->buildPipeline('sanitizer', ['trim', 'lowercase']);
 * $validate = $builder->buildPipeline('validator', ['required', 'email']);
 *
 * $signup = $sanitize->withPipeline($validate);
 *
 * assert(count($signup) === 4);
 * assert($signup->process('  Foo@Example.COM ') === 'foo@example.com');
 * ```
 *
 * 6.6 Attach metrics
 * ------------------
 *
 * ```php
 * $log = new StageLog();
 * $pipeline = $builder->buildPipeline('sanitizer', ['trim', 'lowercase'])->withObserver($log);
 *
 * $pipeline->process(' X ');
 *
 * assert($log->lines === ['#0 TrimProcessor ok', '#1 LowercaseProcessor ok']);
 * assert(min($log->durationsNs) >= 0);
 * ```
 *
 * 6.7 Detach the observer
 * -----------------------
 *
 * ```php
 * $log = new StageLog();
 * $observed = $builder->buildPipeline('sanitizer', ['trim'])->withObserver($log);
 *
 * $observed->withObserver(null)->process(' x ');
 * assert($log->lines === []);
 *
 * $observed->process(' x ');
 * assert(count($log->lines) === 1);
 * ```
 *
 * 6.8 Handle a failure
 * --------------------
 *
 * Read the structured context rather than parsing the message.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
 *
 * $pipeline = $builder->buildPipeline('validator', ['required', 'email']);
 * $caught = null;
 *
 * try {
 *     $pipeline->process('not-an-email');
 * } catch (PipelineExecutionException $e) {
 *     $caught = $e;
 * }
 *
 * assert($caught instanceof PipelineExecutionException);
 * assert($caught->context['stage'] === 1);
 * assert($caught->context['processor'] === EmailValidator::class);
 * assert($caught->getPrevious() instanceof \InvalidArgumentException);
 * assert($caught->getMessage() === '[PP_8520] Pipeline failed at stage 1 (processor: EmailValidator).');
 * assert($caught->getPrevious()->getMessage() === 'Invalid email.'); // the cause stays out of the message
 * ```
 *
 * 6.9 Reach the root cause through nested pipelines
 * -------------------------------------------------
 *
 * A processor that runs its own pipeline produces a chain: the outer stage
 * wraps the inner pipeline's failure, which wraps the original throwable.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 *
 * $inner = $builder->buildPipeline('validator', ['required']);
 * $outer = new Pipeline([new TrimProcessor(), new NestedPipelineProcessor($inner)]);
 * $caught = null;
 *
 * try {
 *     $outer->process('   ');
 * } catch (PipelineExecutionException $e) {
 *     $caught = $e;
 * }
 *
 * assert($caught instanceof PipelineExecutionException);
 * assert($caught->context['stage'] === 1);
 * assert($caught->context['processor'] === NestedPipelineProcessor::class);
 *
 * $nested = $caught->getPrevious();
 * assert($nested instanceof PipelineExecutionException);
 * assert($nested->context['processor'] === RequiredValidator::class);
 *
 * $root = $caught;
 * while ($root->getPrevious() !== null) {
 *     $root = $root->getPrevious();
 * }
 * assert($root->getMessage() === 'Value is required.');
 * ```
 *
 * 6.10 Keep going and collect errors
 * ----------------------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $pipeline = new Pipeline([
 *     new ProcessorHandler(new RequiredValidator(), $results, haltOnError: false),
 *     new ProcessorHandler(new EmailValidator(), $results, haltOnError: false),
 * ]);
 *
 * assert($pipeline->process('') === '');
 * assert($results->errorCount === 2);
 * assert(array_keys($results->errors) === [RequiredValidator::class, EmailValidator::class]);
 * ```
 *
 * 6.11 Write a decorator that keeps stage names accurate
 * ------------------------------------------------------
 *
 * ```php
 * use KaririCode\Contract\Processor\Processor;
 * use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 *
 * final readonly class RetryOnce implements ProcessorDecorator
 * {
 *     public function __construct(private Processor $inner)
 *     {
 *     }
 *
 *     public function innerProcessor(): Processor
 *     {
 *         return $this->inner;
 *     }
 *
 *     public function process(mixed $input): mixed
 *     {
 *         try {
 *             return $this->inner->process($input);
 *         } catch (\RuntimeException) {
 *             return $this->inner->process($input);
 *         }
 *     }
 * }
 *
 * $log = new StageLog();
 * new Pipeline([new RetryOnce(new TrimProcessor())], $log)->process(' a ');
 *
 * assert($log->lines === ['#0 TrimProcessor ok']);
 * ```
 *
 * 6.12 Build once, run many
 * -------------------------
 *
 * In a worker (RoadRunner, FrankenPHP, Swoole), build at boot and reuse:
 * building costs several times more than running (§12).
 *
 * ```php
 * // illustrative
 * $pipeline = $builder->buildPipeline('sanitizer', ['trim', 'lowercase']); // once, at boot
 *
 * while ($request = $worker->waitRequest()) {                               // per request
 *     $email = $pipeline->process($request->getParsedBody()['email'] ?? '');
 * }
 * ```
 *
 * 7. Execution Algorithm
 * ======================
 *
 * ```
 * Algorithm: Sequential Pipeline Execution
 * Input:  pipeline π (n processors, optional observer ω), data d
 * Output: transformed data d'
 *
 *  1. state ← d
 *  2. FOR i = 0 TO n-1 DO
 *  3.   t₀ ← now()                                  // only when ω is present
 *  4.   TRY
 *  5.     state ← π.processors[i].process(state)
 *  6.   CATCH error
 *  7.     name ← innermost(π.processors[i])         // looks through decorators
 *  8.     ω.stageFailed(i, name, now() − t₀, error)
 *  9.     THROW PipelineExecutionException(stage=i, processor=name, previous=error)
 * 10.   END TRY
 * 11.   ω.stageSucceeded(i, innermost(π.processors[i]), now() − t₀)
 * 12. END FOR
 * 13. RETURN state
 * ```
 *
 * `innermost(p)` is {@see StageName::of()}: it follows `innerProcessor()`
 * while p is a ProcessorDecorator, for at most 16 hops, and returns the
 * class name of the processor where it stops. ProcessorHandler uses the
 * same function, so every channel agrees (§10).
 *
 * 8. Worked Traces
 * ================
 *
 * 8.1 Success — `buildPipeline('sanitizer', ['trim', 'lowercase'])`, input
 * `'  HeLLo  '`, StageLog attached:
 *
 * | Stage | Processor          | State before  | State after | Observer call            |
 * |-------|--------------------|---------------|-------------|--------------------------|
 * | 0     | TrimProcessor      | '  HeLLo  '   | 'HeLLo'     | stageSucceeded(0, …)     |
 * | 1     | LowercaseProcessor | 'HeLLo'       | 'hello'     | stageSucceeded(1, …)     |
 *
 * 8.2 Failure — `buildPipeline('validator', ['required', 'email'])`, input
 * `'nope'`: stage 0 passes, stage 1 throws, and the observer sees both.
 *
 * ```php
 * $log = new StageLog();
 * $pipeline = $builder->buildPipeline('validator', ['required', 'email'])->withObserver($log);
 *
 * try {
 *     $pipeline->process('nope');
 * } catch (\Throwable) {
 * }
 *
 * assert($log->lines === ['#0 RequiredValidator ok', '#1 EmailValidator failed: Invalid email.']);
 * ```
 *
 * 9. Error Semantics
 * ==================
 *
 * What is caught: every \Throwable raised by a stage — Exception subclasses
 * and Error subclasses alike (TypeError, ValueError, DivisionByZeroError).
 *
 * What is raised: {@see PipelineExecutionException::atStage()}, with:
 *
 * | Context key  | Value                                       |
 * |--------------|---------------------------------------------|
 * | `processor`  | stage name (innermost class, FQCN)          |
 * | `stage`      | zero-based stage index                      |
 * | `causeClass` | class of the throwable raised by the stage  |
 *
 * Message format: `[PP_8520] Pipeline failed at stage {i} (processor: {name}).`
 * The cause's message is never copied into it: a processor's message can
 * carry the rejected input (Spec V4.3.3 §20.3). Read it through
 * `getPrevious()`, and match on the code and context keys in code.
 *
 * Three cases escape without that wrapping:
 *
 * - An observer that throws from stageSucceeded() aborts the run with its
 *   own exception.
 * - An observer that throws from stageFailed() *replaces* the stage
 *   failure, which is then lost. Observers must not throw.
 * - A constructor element that is not a Processor is a programming error.
 *   PHPStan rejects it statically; at run time it surfaces as a TypeError.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Observer\PipelineObserver;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 *
 * final class ExplodingObserver implements PipelineObserver
 * {
 *     public function stageSucceeded(int $stageIndex, string $processorClass, int $durationNs): void
 *     {
 *     }
 *
 *     public function stageFailed(int $stageIndex, string $processorClass, int $durationNs, \Throwable $cause): void
 *     {
 *         throw new \LogicException('observer bug');
 *     }
 * }
 *
 * $caught = null;
 * try {
 *     new Pipeline([new RequiredValidator()], new ExplodingObserver())->process('');
 * } catch (\Throwable $e) {
 *     $caught = $e;
 * }
 * assert($caught instanceof \LogicException); // the stage failure was replaced
 *
 * $caught = null;
 * try {
 *     new Pipeline(['not a processor'])->process('x');
 * } catch (\TypeError $e) {
 *     $caught = $e;
 * }
 * assert($caught instanceof \TypeError);
 * ```
 *
 * 10. Observability Channels
 * ==========================
 *
 * All four channels identify a stage by the same stage name (§2):
 *
 * | Channel                                 | Produced by               | Carries                         |
 * |-----------------------------------------|---------------------------|---------------------------------|
 * | PipelineExecutionException context      | this class, on failure    | stage, processor, causeClass    |
 * | PipelineObserver calls                  | this class, every stage   | stage, processor, duration (ns) |
 * | ProcessingResultCollection trace        | ProcessorHandler          | processor per execution         |
 * | ProcessingResultCollection errors       | ProcessorHandler          | processor, errorKey, message    |
 *
 * The first two exist for every pipeline; the last two only for processors
 * wrapped in a ProcessorHandler.
 *
 * 11. Formal Properties
 * =====================
 *
 * Theorem 11.1 (Termination). Execution terminates if every processor and
 * the observer terminate.
 * Proof. n is finite and iteration is sequential; each iteration makes one
 * process() call, at most two observer calls and one decorator walk, which
 * is bounded (StageName::MAX_DECORATOR_DEPTH = 16) even for cyclic
 * decorators. ∎
 *
 * Theorem 11.2 (Trace preservation). If stage i fails because a nested
 * pipeline failed at stage j, the raised exception carries stage i and its
 * `previous` carries stage j.
 * Proof. Step 9 of §7 wraps every throwable, including a
 * PipelineExecutionException, with `previous = error`. ∎
 *
 * Theorem 11.3 (Associativity). For pipelines a, b, c:
 * `a.withPipeline(b).withPipeline(c)` and `a.withPipeline(b.withPipeline(c))`
 * hold the same processor sequence, and so produce the same output for
 * every input. Both keep a's observer.
 * Proof. withPipeline() concatenates lists, and list concatenation is
 * associative; the left operand's observer is kept at every step. ∎
 *
 * Theorem 11.4 (Identity). The empty pipeline e returns its input
 * unchanged, and for every pipeline p, `p.withPipeline(e)` and
 * `e.withPipeline(p)` hold p's processor sequence.
 * Proof. With n = 0 the loop does not run and step 13 returns d;
 * concatenating an empty list is the identity. ∎
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 *
 * $a = $builder->buildPipeline('sanitizer', ['trim']);
 * $b = $builder->buildPipeline('sanitizer', ['lowercase']);
 * $c = $builder->buildPipeline('validator', ['email']);
 * $empty = new Pipeline();
 *
 * $left = $a->withPipeline($b)->withPipeline($c);
 * $right = $a->withPipeline($b->withPipeline($c));
 * assert($left->getProcessors() === $right->getProcessors());
 * assert($left->process(' A@B.CO ') === $right->process(' A@B.CO '));
 *
 * assert($empty->process('same') === 'same');
 * assert($a->withPipeline($empty)->getProcessors() === $a->getProcessors());
 * assert($empty->withPipeline($a)->getProcessors() === $a->getProcessors());
 * ```
 *
 * 12. Complexity and Measured Cost
 * ================================
 *
 * process() runs in O(n·p) time and O(1) extra space. Naming a stage adds
 * O(k), k ≤ 16, paid only on failure or when an observer is attached. The
 * with*() methods copy the stage list: O(n) time and space.
 *
 * Measured on PHP 8.4.26 CLI with opcache and JIT disabled, 5 identity
 * stages, 200 000 runs, two runs per figure (2026-09-29). Indicative only;
 * measure in your own runtime, where opcache normally lowers every figure.
 *
 * | Scenario                                     | Cost per run             |
 * |----------------------------------------------|--------------------------|
 * | bare foreach over the same processors        | ~0.52 µs                 |
 * | process(), no observer                       | ~0.93 µs (+~83 ns/stage) |
 * | process(), no-op observer                    | ~3.3–3.8 µs (+~0.5 µs/stage) |
 * | ProcessorBuilder::buildPipeline(), 5 names   | ~3.8–4.0 µs              |
 *
 * The no-observer overhead is the try/catch frame, the nullsafe check and
 * the property access. With an observer, each stage adds two hrtime()
 * reads, the decorator walk and the observer call.
 *
 * 13. Concurrency and Long-Running Runtimes
 * =========================================
 *
 * A Pipeline instance can be shared freely: between requests in a worker,
 * between Fibers, between Swoole coroutines. The travelling state lives in
 * a local variable of process(), so concurrent runs cannot see each
 * other's data through the pipeline.
 *
 * What the pipeline cannot make safe is what it holds:
 *
 * - Processors with internal state (e.g. the contract's ValidatableProcessor,
 *   which remembers isValid() between calls) must not run concurrently in
 *   two pipelines, or twice concurrently in one.
 * - Observers receive calls from every run that shares them; aggregate
 *   with that in mind.
 * - A ProcessingResultCollection must be created per execution (§6.10).
 *
 * 14. Design Decisions
 * ====================
 *
 * - Immutability over `pipe()` mutation — ADR-001.
 * - Every failure is chained, including nested pipelines' — ADR-006.
 * - Stages are named after the innermost decorated processor — ADR-006.
 * - Metrics through an optional, vendor-neutral observer — ADR-006.
 * - The contract's `Pipeline` interface (`addProcessor(): self`) is not
 *   implemented: copy semantics behind a mutating signature would silently
 *   drop processors — ADR-004.
 * - A Pipeline is not itself a Processor. To run one as a stage, wrap it in
 *   a small adapter (§6.9).
 *
 * 15. Anti-Patterns
 * =================
 *
 * - Discarding the result of a with*() call. `$pipeline->withProcessor($p);`
 *   builds a new pipeline and throws it away; `$pipeline` is unchanged.
 * - Mutating objects passed through the pipeline. Return a new object; P1
 *   relies on it and the pipeline cannot check it.
 * - Throwing from an observer (§9).
 * - Relying on constructor keys for ordering (§6.3).
 * - Building a pipeline inside a hot loop. Building costs several runs
 *   (§12); build once and reuse.
 * - Catching \Throwable around process() and discarding it. Log or rethrow
 *   the PipelineExecutionException: its context is the trace.
 *
 * 16. Testing Code That Uses a Pipeline
 * =====================================
 *
 * - Use a real Pipeline with small, deterministic processors; mocking
 *   Pipeline itself is impossible (final) and unnecessary.
 * - Assert on outcomes: the returned value, or the exception's `stage`
 *   and `processor` context keys — not on message strings.
 * - To assert which stages ran, attach a recording observer (StageLog in
 *   the prelude, RecordingObserver in the test suite) and compare its log.
 * - Durations vary between runs; assert only that they are ≥ 0.
 *
 * 17. Migration from V1.x
 * =======================
 *
 * | V1.x                                   | V4.0                                        |
 * |----------------------------------------|---------------------------------------------|
 * | `$pipeline->pipe($p)` mutates          | `$pipeline = $pipeline->withProcessor($p)`  |
 * | Nested pipeline error rethrown as-is   | Wrapped; the inner one is `getPrevious()`   |
 * | Stage named after the wrapper          | Stage named after the innermost processor   |
 * | No metrics                             | `withObserver()`                            |
 *
 * 18. FAQ
 * =======
 *
 * Why did withProcessor() not change my pipeline?
 *   It returns a new pipeline; assign the result (§15).
 *
 * How do I continue after a stage fails?
 *   Wrap the processors in ProcessorHandler with `haltOnError: false` and
 *   read the errors from a ProcessingResultCollection (§6.10).
 *
 * Why does the exception name a different class than the one I registered?
 *   You registered a decorator; stages are named after the innermost
 *   processor, which is the one that did the work.
 *
 * Does the observer slow processing down?
 *   Measurably: about 0.5 µs per stage in the measurement of §12. Without
 *   an observer the clock is never read.
 *
 * Can stages run in parallel or asynchronously?
 *   No. Execution is sequential and synchronous (§3, P2).
 *
 * Can the same processor appear twice?
 *   Yes: `['trim', 'lowercase', 'trim']` runs TrimProcessor at stages 0
 *   and 2.
 *
 * 19. Writing Processors That Compose Well
 * =========================================
 *
 * The pipeline guarantees order, isolation of its own structure and a
 * faithful trace. The rest depends on the processors:
 *
 * - Return a new value; never mutate an object you received. A processor
 *   that mutates its input changes the caller's object and every branch
 *   built from the same value (P1 is then violated by the data, not by the
 *   pipeline).
 * - Throw to reject. A validator signals failure by throwing; returning a
 *   sentinel such as false would flow into the next stage as data.
 * - Keep messages safe to show. ProcessorHandler copies the exception
 *   message into the result collection, which often reaches end users.
 * - Keep configuration in configure(), not in process(). Configured
 *   entries are clones (ADR-005); per-run state in properties breaks
 *   concurrent use (§13).
 * - Do not depend on your position. A processor may appear at any stage,
 *   or at several.
 *
 * ```php
 * use KaririCode\Contract\Processor\Processor;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 *
 * final class MutatingTagger implements Processor
 * {
 *     public function process(mixed $input): mixed
 *     {
 *         $input->tags[] = 'seen'; // changes the caller's object
 *
 *         return $input;
 *     }
 * }
 *
 * final class CopyingTagger implements Processor
 * {
 *     public function process(mixed $input): mixed
 *     {
 *         $copy = clone $input;
 *         $copy->tags[] = 'seen';
 *
 *         return $copy;
 *     }
 * }
 *
 * $original = new \stdClass();
 * $original->tags = [];
 * new Pipeline([new MutatingTagger()])->process($original);
 * assert($original->tags === ['seen']); // the caller's object was modified
 *
 * $original = new \stdClass();
 * $original->tags = [];
 * $result = new Pipeline([new CopyingTagger()])->process($original);
 * assert($original->tags === []);       // untouched
 * assert($result->tags === ['seen']);
 * ```
 *
 * @since     1.0.0
 *
 * @see \KaririCode\ProcessorPipeline\ProcessorBuilder           Construction
 * @see \KaririCode\ProcessorPipeline\ProcessorRegistry          Registration
 * @see \KaririCode\ProcessorPipeline\Observer\PipelineObserver  Stage metrics
 */
final readonly class Pipeline implements \Countable
{
    /** @var list<Processor> */
    private array $processors;

    /**
     * Create a pipeline from an ordered list of processors.
     *
     * The array's iteration order defines the stage order and its keys are
     * discarded (invariant I1, §6.3). Elements must be Processor instances:
     * PHPStan enforces this statically, and §9 describes what happens at run
     * time if the rule is broken.
     *
     * The observer, when given, receives one call per executed stage (§10).
     * It can be attached or replaced later with {@see withObserver()}.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
     *
     * $log = new StageLog();
     * $pipeline = new Pipeline([new TrimProcessor()], $log);
     *
     * $pipeline->process(' a ');
     * assert($log->lines === ['#0 TrimProcessor ok']);
     * ```
     *
     * @param array<array-key, Processor> $processors Ordered processors; keys are discarded
     * @param PipelineObserver|null       $observer   Per-stage metrics receiver (ARFA P5)
     */
    public function __construct(
        array $processors = [],
        private ?PipelineObserver $observer = null,
    ) {
        $this->processors = array_values($processors);
    }

    /**
     * Run every processor in order, feeding each one the previous output.
     *
     * Preconditions:  none — any value is accepted, including null.
     * Postconditions: returns the output of the last stage, or `$input`
     *                 unchanged when the pipeline is empty (Theorem 11.4).
     * On failure:     no later stage runs; the observer, if any, receives
     *                 stageFailed(); a PipelineExecutionException is thrown
     *                 with the stage's throwable as its previous exception
     *                 (invariant I4, §9).
     *
     * ```php
     * $pipeline = $builder->buildPipeline('validator', ['required', 'length' => ['maxLength' => 5]]);
     *
     * assert($pipeline->process('abc') === 'abc');
     * ```
     *
     * @param mixed $input Data to process
     *
     * @return mixed Processed result after all stages
     *
     * @throws PipelineExecutionException When any stage throws; the cause is chained
     */
    public function process(mixed $input): mixed
    {
        // The Processor contract is `mixed → mixed`: pipeline state is untyped by design.
        /** @psalm-suppress MixedAssignment */
        $state = $input;

        foreach ($this->processors as $index => $processor) {
            $startedAt = $this->observer === null ? 0 : self::now();

            try {
                /** @psalm-suppress MixedAssignment */
                $state = $processor->process($state);
            } catch (\Throwable $throwable) {
                $processorClass = StageName::of($processor);
                $this->observer?->stageFailed($index, $processorClass, self::now() - $startedAt, $throwable);

                throw PipelineExecutionException::atStage(
                    processorName: $processorClass,
                    stageIndex: $index,
                    cause: $throwable,
                );
            }

            // Nullsafe short-circuit: arguments are not evaluated without an observer.
            $this->observer?->stageSucceeded($index, StageName::of($processor), self::now() - $startedAt);
        }

        return $state;
    }

    /**
     * Create a new pipeline with an additional processor appended.
     *
     * IMMUTABLE — returns a new Pipeline instance; the observer is kept.
     * The current instance is never modified (ARFA 1.3 P1). Cost: O(n) to
     * copy the stage list.
     *
     * ```php
     * $base = $builder->buildPipeline('sanitizer', ['trim']);
     * $base->withProcessor(new UpperCaseProcessor()); // result discarded: no effect
     *
     * assert(count($base) === 1);
     * ```
     */
    public function withProcessor(Processor $processor): self
    {
        return new self([...$this->processors, $processor], $this->observer);
    }

    /**
     * Create a new pipeline with another pipeline's processors appended.
     *
     * IMMUTABLE — returns a new Pipeline instance. The observer of this
     * (left-hand) pipeline is kept; the other pipeline's observer is not.
     * Composition is associative with the empty pipeline as identity
     * (Theorems 11.3 and 11.4). Cost: O(n + m).
     *
     * ```php
     * $log = new StageLog();
     * $left = $builder->buildPipeline('sanitizer', ['trim'])->withObserver($log);
     * $right = $builder->buildPipeline('sanitizer', ['lowercase']);
     *
     * $left->withPipeline($right)->process(' A ');
     * assert(count($log->lines) === 2); // both stages reported to the left observer
     * ```
     */
    public function withPipeline(self $other): self
    {
        return new self([...$this->processors, ...$other->processors], $this->observer);
    }

    /**
     * Create a new pipeline reporting stage metrics to the given observer.
     *
     * IMMUTABLE — returns a new Pipeline instance. Passing null detaches the
     * observer (§6.7). The observer contract, including the rule that it must
     * not throw, is documented on {@see PipelineObserver}.
     */
    public function withObserver(?PipelineObserver $observer): self
    {
        return new self($this->processors, $observer);
    }

    /**
     * The stages in execution order, keyed 0 … n − 1.
     *
     * Arrays are values in PHP: changing the returned list does not change
     * the pipeline. The processors in it are the same objects the pipeline
     * runs.
     *
     * @return list<Processor>
     */
    public function getProcessors(): array
    {
        return $this->processors;
    }

    /**
     * Number of stages; makes `count($pipeline)` work (\Countable).
     */
    #[\Override]
    public function count(): int
    {
        return count($this->processors);
    }

    /**
     * Whether the pipeline has no stages. An empty pipeline returns its
     * input unchanged (Theorem 11.4).
     */
    public function isEmpty(): bool
    {
        return $this->processors === [];
    }

    /**
     * Monotonic clock in nanoseconds.
     *
     * hrtime(true) is unaffected by wall-clock adjustments (NTP, DST). The
     * cast covers platforms where it returns a float.
     */
    private static function now(): int
    {
        return (int) hrtime(true);
    }
}
