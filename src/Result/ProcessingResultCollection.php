<?php

declare(strict_types=1);

namespace KaririCode\ProcessorPipeline\Result;

/**
 * Mutable accumulator for the errors, warnings and execution trace of a run.
 *
 * Contents
 * ========
 *
 *  1. Overview                        10. Merging
 *  2. Three Failure Channels          11. Complexity
 *  3. Identity                        12. Concurrency and Long-Running Runtimes
 *  4. Data Shapes                     13. Design Decisions
 *  5. ARFA 1.3 Compliance             14. Anti-Patterns
 *  6. Invariants                      15. Testing
 *  7. API at a Glance                 16. Migration from V1.x
 *  8. Lifecycle                       17. FAQ
 *  9. Usage Cookbook                 18. The Returned Value When Errors Exist
 *                                    19. Choosing haltOnError
 *                                    20. Recording Your Own Error Keys
 *                                    21. Summaries, Logging and Transport
 *
 * 1. Overview
 * ===========
 *
 * A ProcessingResultCollection records what happened during a run without
 * stopping it: errors, warnings, and the order in which processors ran. It
 * is filled by {@see \KaririCode\ProcessorPipeline\Handler\ProcessorHandler},
 * which catches a processor's failure, records it and lets the pipeline
 * continue, and by any application code that adds entries of its own.
 *
 * It is deliberately mutable. An accumulator that returned a new instance
 * per entry would force every handler to pass results back through the
 * pipeline's data, mixing diagnostics into the value being processed.
 * Create one collection per execution (§8, §12).
 *
 * 2. Three Failure Channels
 * =========================
 *
 * | Channel                    | Stops the run? | Carries                       | Use it for                      |
 * |----------------------------|----------------|-------------------------------|---------------------------------|
 * | PipelineExecutionException | yes            | stage, processor, cause chain | failures the caller must handle |
 * | this collection            | no             | processor, errorKey, message  | "report every problem" flows    |
 * | PipelineObserver           | no             | stage, processor, duration    | metrics and tracing             |
 *
 * A pipeline without ProcessorHandler never writes to a collection. A
 * handler with `haltOnError: true` (the default) records the error *and*
 * rethrows it, so that failure appears in the first two channels.
 *
 * 3. Identity
 * ===========
 *
 * Errors, warnings and the execution trace are keyed by the identifier the
 * caller passes. ProcessorHandler passes the stage name: the class of the
 * innermost processor, resolved through decorators by
 * {@see \KaririCode\ProcessorPipeline\Handler\StageName}. That is the same
 * identity PipelineExecutionException and PipelineObserver use, so all four
 * observability channels agree. Keys are never shortened: two processors
 * named `Email` in different namespaces stay separate (§9.4).
 *
 * Unlike V1.x, which keyed errors by *property* through the contract's
 * ProcessingResult, this collection is keyed by *processor*. Grouping by
 * property is the consumer's job — one collection per property (§9.3).
 *
 * Identifiers are array keys, and PHP stores a numeric string such as '0'
 * as the int 0. Class names never look numeric, so keys written by
 * ProcessorHandler are always strings; identifiers you pass yourself are
 * returned as array-key.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $log = new StageLog();
 *
 * new Pipeline([new ProcessorHandler(new RequiredValidator(), $results, haltOnError: false)], $log)->process('');
 *
 * // The observer, the trace and the error key name the same processor.
 * assert($log->lines === ['#0 RequiredValidator ok']); // the handler absorbed the failure
 * assert($results->executionTrace === [RequiredValidator::class]);
 * assert(array_keys($results->errors) === [RequiredValidator::class]);
 * ```
 *
 * 4. Data Shapes
 * ==============
 *
 * ```
 * errors, warnings : array<array-key, list<array{errorKey: string, message: string}>>
 * executionTrace   : list<string>
 * ```
 *
 * After the run in §9.1 the collection holds:
 *
 * ```
 * errors = [
 *     'RequiredValidator' => [['errorKey' => 'processingFailed', 'message' => 'Value is required.']],
 *     'EmailValidator'    => [['errorKey' => 'processingFailed', 'message' => 'Invalid email.']],
 * ]
 * warnings       = []
 * executionTrace = ['TrimProcessor', 'RequiredValidator', 'EmailValidator']
 * ```
 *
 * Groups appear in the order of their first entry; entries within a group
 * in the order they were added.
 *
 * 5. ARFA 1.3 Compliance
 * ======================
 *
 * P1 (Immutable State Transformation) — scope:
 *   This is a mutable accumulator by design: `addError()`, `addWarning()`,
 *   `recordExecution()`, `merge()` and `reset()` modify the instance. The
 *   property hooks give read-only *access* (assigning `$results->errors`
 *   throws an Error at run time) but do not make the object immutable. Use one collection
 *   per execution.
 *
 * P5 (Continuous Observability):
 *   `recordExecution()` builds an execution trace, and every error or
 *   warning names the processor that produced it (§3).
 *
 * 6. Invariants
 * =============
 *
 * C1  Entries are only ever appended; nothing but reset() removes one.
 * C2  errorCount equals the total number of error entries across groups.
 * C3  hasErrors is true exactly when errorCount > 0; hasWarnings likewise
 *     for warnings.
 * C4  merge() appends the other collection's entries and trace after this
 *     one's, and never modifies the other collection.
 * C5  ProcessorHandler records the trace entry *before* calling the
 *     processor, so a failing processor appears in the trace too.
 *
 * 7. API at a Glance
 * ==================
 *
 * | Member                                   | Kind   | Notes                                |
 * |------------------------------------------|--------|--------------------------------------|
 * | addError($processor, $errorKey, $msg)    | method | appends to the processor's group     |
 * | addWarning($processor, $errorKey, $msg)  | method | same, for non-fatal findings         |
 * | recordExecution($processor)              | method | appends to the trace                 |
 * | merge($other)                            | method | appends everything from $other       |
 * | reset()                                  | method | empties all three                    |
 * | $errors / getErrors()                    | hook / method | grouped error entries         |
 * | $warnings / getWarnings()                | hook / method | grouped warning entries       |
 * | $executionTrace / getExecutionTrace()    | hook / method | processors in execution order |
 * | $hasErrors / hasErrors()                 | hook / method | bool                          |
 * | $hasWarnings / hasWarnings()             | hook / method | bool                          |
 * | $errorCount                              | hook   | total error entries                  |
 *
 * The hooks read naturally in conditions (`if ($results->hasErrors)`); the
 * methods work as callables (`array_filter($all, fn ($r) => $r->hasErrors())`).
 * Both read the same data.
 *
 * 8. Lifecycle
 * ============
 *
 * ```
 * new ──▶ handlers record ──▶ caller reads ──▶ discard
 *              ▲    (addError, recordExecution)      │
 *              └──────────── reset() ◀───────────────┘  sequential reuse only (§9.7)
 *
 * several collections ──merge()──▶ one summary (§10)
 * ```
 *
 * 9. Usage Cookbook
 * =================
 *
 * Examples run in the test suite with the conventions of Pipeline §6.
 *
 * 9.1 Collect every error of a run
 * --------------------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $pipeline = new Pipeline([
 *     new ProcessorHandler(new TrimProcessor(), $results, haltOnError: false),
 *     new ProcessorHandler(new RequiredValidator(), $results, haltOnError: false),
 *     new ProcessorHandler(new EmailValidator(), $results, haltOnError: false),
 * ]);
 *
 * $pipeline->process('   ');
 *
 * assert($results->hasErrors && $results->errorCount === 2);
 * assert($results->executionTrace === [TrimProcessor::class, RequiredValidator::class, EmailValidator::class]);
 * assert($results->errors === [
 *     RequiredValidator::class => [['errorKey' => 'processingFailed', 'message' => 'Value is required.']],
 *     EmailValidator::class => [['errorKey' => 'processingFailed', 'message' => 'Invalid email.']],
 * ]);
 * ```
 *
 * 9.2 Read the entries
 * --------------------
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $results->addError('App\\Rule\\Email', 'invalidFormat', 'Invalid email.');
 * $results->addError('App\\Rule\\Email', 'blockedDomain', 'Domain not allowed.');
 * $results->addError('App\\Rule\\Length', 'tooShort', 'Too short.');
 *
 * $lines = [];
 * foreach ($results->errors as $processor => $entries) {
 *     foreach ($entries as $entry) {
 *         $lines[] = "{$processor}: [{$entry['errorKey']}] {$entry['message']}";
 *     }
 * }
 *
 * assert($lines === [
 *     'App\\Rule\\Email: [invalidFormat] Invalid email.',
 *     'App\\Rule\\Email: [blockedDomain] Domain not allowed.',
 *     'App\\Rule\\Length: [tooShort] Too short.',
 * ]);
 * ```
 *
 * 9.3 One collection per property
 * -------------------------------
 *
 * The handler knows the processor, not the property being processed. To
 * report errors per field, as V1.x did, give each property its own
 * collection:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $input = ['name' => '', 'email' => 'not-an-email'];
 * $rules = [
 *     'name' => [new RequiredValidator()],
 *     'email' => [new RequiredValidator(), new EmailValidator()],
 * ];
 *
 * $byProperty = [];
 * foreach ($rules as $property => $processors) {
 *     $results = new ProcessingResultCollection();
 *     $stages = array_map(static fn ($processor) => new ProcessorHandler($processor, $results, haltOnError: false), $processors);
 *     new Pipeline($stages)->process($input[$property]);
 *     $byProperty[$property] = $results;
 * }
 *
 * $messages = [];
 * foreach ($byProperty as $property => $results) {
 *     foreach ($results->errors as $entries) {
 *         foreach ($entries as $entry) {
 *             $messages[$property][] = $entry['message'];
 *         }
 *     }
 * }
 *
 * assert($messages === ['name' => ['Value is required.'], 'email' => ['Invalid email.']]);
 * ```
 *
 * 9.4 Short names for display
 * ---------------------------
 *
 * Shorten identities only at the presentation edge, and never use the
 * short form as a key: two processors can share it.
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $results->addError('App\\Validation\\Email', 'invalid', 'Invalid email.');
 * $results->addError('Vendor\\Rules\\Email', 'invalid', 'Rejected by the vendor rule.');
 *
 * $shortName = static fn (string $class): string => substr((string) strrchr('\\' . $class, '\\'), 1);
 * $labels = array_map($shortName, array_map(strval(...), array_keys($results->errors)));
 *
 * assert($labels === ['Email', 'Email']); // one label, two processors
 * assert(count($results->errors) === 2);  // the collection keeps them apart
 * ```
 *
 * 9.5 Warnings
 * ------------
 *
 * ProcessorHandler records errors only. Warnings come from code that holds
 * the collection — typically the application, around the pipeline:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $raw = '  hello ';
 * $clean = $builder->buildPipeline('sanitizer', ['trim'])->process($raw);
 *
 * if ($clean !== $raw) {
 *     $results->addWarning(TrimProcessor::class, 'whitespaceRemoved', 'Leading or trailing spaces were removed.');
 * }
 *
 * assert($results->hasWarnings && !$results->hasErrors);
 * assert($results->warnings[TrimProcessor::class][0]['errorKey'] === 'whitespaceRemoved');
 * ```
 *
 * 9.6 An API response without toArray()
 * -------------------------------------
 *
 * The collection has no generic export. Map what you expose, explicitly:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $results->addError(EmailValidator::class, 'processingFailed', 'Invalid email.');
 *
 * $payload = [
 *     'valid' => !$results->hasErrors,
 *     'errors' => array_map(
 *         static fn (array $entries): array => array_column($entries, 'message'),
 *         $results->errors,
 *     ),
 * ];
 *
 * assert(json_encode($payload) === '{"valid":false,"errors":{"EmailValidator":["Invalid email."]}}');
 * ```
 *
 * 9.7 Reuse across sequential runs
 * --------------------------------
 *
 * A handler is bound to its collection at construction. To reuse one
 * pipeline for several inputs, reset the collection before each run:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $pipeline = new Pipeline([new ProcessorHandler(new RequiredValidator(), $results, haltOnError: false)]);
 *
 * $counts = [];
 * foreach (['', 'ok', ''] as $input) {
 *     $results->reset(); // without it, earlier errors leak into later runs
 *     $pipeline->process($input);
 *     $counts[] = $results->errorCount;
 * }
 *
 * assert($counts === [1, 0, 1]);
 * ```
 *
 * This is safe only when runs cannot overlap. With concurrent runs, build
 * a pipeline and a collection per run (§12).
 *
 * 10. Merging
 * ===========
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $name = new ProcessingResultCollection();
 * $name->recordExecution(RequiredValidator::class);
 * $name->addError(RequiredValidator::class, 'processingFailed', 'Value is required.');
 *
 * $email = new ProcessingResultCollection();
 * $email->recordExecution(RequiredValidator::class);
 * $email->recordExecution(EmailValidator::class);
 * $email->addError(EmailValidator::class, 'processingFailed', 'Invalid email.');
 *
 * $summary = new ProcessingResultCollection();
 * $summary->merge($name);
 * $summary->merge($email);
 *
 * assert($summary->errorCount === 2);
 * assert($summary->executionTrace === [RequiredValidator::class, RequiredValidator::class, EmailValidator::class]);
 * assert($name->errorCount === 1); // merge() never modifies its argument (C4)
 * ```
 *
 * Merging groups entries by processor, so it loses the per-property view
 * of §9.3. Keep the per-property map when you need both.
 *
 * 11. Complexity
 * ==============
 *
 * | Operation                                  | Time                  |
 * |--------------------------------------------|-----------------------|
 * | addError(), addWarning(), recordExecution()| O(1) amortized        |
 * | errors, warnings, executionTrace (read)    | O(1), copy-on-write   |
 * | hasErrors, hasWarnings                     | O(1)                  |
 * | errorCount                                 | O(g), g = error groups|
 * | merge($other)                              | O(e + w + t)          |
 * | reset()                                    | O(1)                  |
 *
 * e, w = entries in $other; t = trace length of both collections (the
 * trace is rebuilt by spreading both lists).
 *
 * 12. Concurrency and Long-Running Runtimes
 * =========================================
 *
 * A collection is not safe to share between runs that overlap — Fibers,
 * Swoole coroutines, parallel requests in one worker. Their entries would
 * interleave, and one run's reset() would erase another's errors. Create a
 * collection, and the handlers bound to it, per run. Building a pipeline
 * costs a few microseconds (ProcessorBuilder §10), far less than a request.
 *
 * 13. Design Decisions
 * ====================
 *
 * - Mutable accumulator: the one mutable object on the execution path,
 *   because diagnostics must not travel inside the processed value (§1).
 * - Keyed by processor, not property: the handler knows the processor;
 *   property grouping belongs to the consumer (§9.3).
 * - One identity everywhere: the innermost class name (§3).
 * - No toArray(): KaririCode avoids generic array exports; consumers map
 *   what they expose (§9.6).
 * - The contract's ProcessingResult is not implemented: it is keyed by
 *   property and requires toArray() and processed-data methods.
 *
 * 14. Anti-Patterns
 * =================
 *
 * - Sharing one collection between overlapping runs (§12).
 * - Reusing a collection across sequential runs without reset() (§9.7).
 * - Using identities as UI labels, or shortening them into keys (§9.4).
 * - Branching on errorKey for entries written by ProcessorHandler: it
 *   always writes 'processingFailed'. Distinguish those entries by
 *   processor; give entries you add yourself specific keys.
 * - Reading hasErrors as "the pipeline failed": with `haltOnError: false`
 *   the pipeline returns normally and the errors are the only signal.
 *
 * 15. Testing
 * ===========
 *
 * - Assert on the `errors` structure with assertSame; it is plain arrays.
 * - Assert which handlers ran through `executionTrace`, including failed
 *   ones (C5).
 * - The collection has no dependencies; exercise addError(), merge() and
 *   reset() directly for your own recording code.
 *
 * 16. Migration from V1.x
 * =======================
 *
 * Verified against kariricode/processor-pipeline v1.3.2:
 *
 * | V1.x                                                   | V4.0                                          |
 * |--------------------------------------------------------|-----------------------------------------------|
 * | implements the contract's ProcessingResult             | does not (§13)                                |
 * | addError($property, $errorKey, $message)               | addError($processorClass, …): same shape;     |
 * |                                                        | the handler now passes a processor identity   |
 * | setProcessedData(), getProcessedData(),                | none: the pipeline returns the processed      |
 * | addProcessedData()                                     | value                                         |
 * | toArray()                                              | none: map explicitly (§9.6)                   |
 * | clear()                                                | reset()                                       |
 * | addProcessingError(ProcessingError)                    | addError()                                    |
 * | —                                                      | warnings, executionTrace, merge(), hooks      |
 *
 * addError() accepts any identifier, so V1-style calls that pass a property
 * name still produce one group per property. What changed is what
 * ProcessorHandler records.
 *
 * 17. FAQ
 * =======
 *
 * Why is errorKey always 'processingFailed' for handler entries?
 *   An exception carries no machine-readable key; the handler records its
 *   message and the processor's identity (§14).
 *
 * Why are the keys long class names?
 *   They are unambiguous (§3). Shorten them for display only (§9.4).
 *
 * Does the collection know which property failed?
 *   No; use one collection per property (§9.3).
 *
 * Can I use it without ProcessorHandler?
 *   Yes. It is a plain accumulator (§9.2, §9.5).
 *
 * 18. The Returned Value When Errors Exist
 * ========================================
 *
 * With `haltOnError: false`, a failing stage passes its *input* through
 * unchanged, and every later stage still runs — on data that failed
 * validation:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $pipeline = new Pipeline([
 *     new ProcessorHandler(new TrimProcessor(), $results, haltOnError: false),
 *     new ProcessorHandler(new EmailValidator(), $results, haltOnError: false),
 *     new ProcessorHandler(new UpperCaseProcessor(), $results, haltOnError: false),
 * ]);
 *
 * $value = $pipeline->process(' not-an-email ');
 *
 * assert($results->hasErrors);
 * assert($value === 'NOT-AN-EMAIL'); // later stages ran on the invalid value
 * ```
 *
 * Treat the returned value as usable only when `hasErrors` is false, or
 * order the pipeline so that nothing after a validator has side effects.
 *
 * 19. Choosing haltOnError
 * ========================
 *
 * | You want                                   | Wrap with                     | Result                              |
 * |--------------------------------------------|-------------------------------|-------------------------------------|
 * | every problem, then decide (opt-in)        | handler, haltOnError: false   | normal return; read the collection  |
 * | stop at the first problem, keep a record   | handler (default)             | exception and one entry             |
 * | stop at the first problem, no record       | no handler                    | exception only                      |
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Exception\PipelineExecutionException;
 * use KaririCode\ProcessorPipeline\Handler\ProcessorHandler;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $pipeline = new Pipeline([
 *     new ProcessorHandler(new RequiredValidator(), $results, haltOnError: true),
 *     new ProcessorHandler(new EmailValidator(), $results),
 * ]);
 *
 * $caught = null;
 * try {
 *     $pipeline->process('');
 * } catch (PipelineExecutionException $e) {
 *     $caught = $e;
 * }
 *
 * assert($caught?->context['processor'] === RequiredValidator::class); // channel 1
 * assert($results->errorCount === 1);                                  // channel 2
 * assert($results->executionTrace === [RequiredValidator::class]);     // EmailValidator never ran
 * ```
 *
 * 20. Recording Your Own Error Keys
 * =================================
 *
 * ProcessorHandler writes 'processingFailed' for every failure because an
 * exception has no machine-readable key. When clients need specific keys,
 * let the processor throw an exception that carries one, and record it
 * with a decorator of your own:
 *
 * ```php
 * use KaririCode\Contract\Processor\Processor;
 * use KaririCode\ProcessorPipeline\Handler\ProcessorDecorator;
 * use KaririCode\ProcessorPipeline\Pipeline\Pipeline;
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * final class RuleViolation extends \InvalidArgumentException
 * {
 *     public function __construct(public readonly string $errorKey, string $message)
 *     {
 *         parent::__construct($message);
 *     }
 * }
 *
 * final class MinimumAgeRule implements Processor
 * {
 *     public function process(mixed $input): mixed
 *     {
 *         if (!is_int($input) || $input < 18) {
 *             throw new RuleViolation('tooYoung', 'Must be at least 18.');
 *         }
 *
 *         return $input;
 *     }
 * }
 *
 * final readonly class KeyedErrorHandler implements ProcessorDecorator
 * {
 *     public function __construct(private Processor $inner, private ProcessingResultCollection $results)
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
 *         $this->results->recordExecution($this->inner::class);
 *         try {
 *             return $this->inner->process($input);
 *         } catch (RuleViolation $violation) {
 *             $this->results->addError($this->inner::class, $violation->errorKey, $violation->getMessage());
 *
 *             return $input;
 *         }
 *     }
 * }
 *
 * $results = new ProcessingResultCollection();
 * new Pipeline([new KeyedErrorHandler(new MinimumAgeRule(), $results)])->process(16);
 *
 * assert($results->errors === [
 *     MinimumAgeRule::class => [['errorKey' => 'tooYoung', 'message' => 'Must be at least 18.']],
 * ]);
 * ```
 *
 * Only RuleViolation is absorbed; any other throwable still stops the run
 * and reaches the caller as a PipelineExecutionException. Implementing
 * ProcessorDecorator keeps the stage named after MinimumAgeRule in the
 * other channels too.
 *
 * 21. Summaries, Logging and Transport
 * ====================================
 *
 * Summaries are one array function away:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $results->addError('A', 'missing', 'first');
 * $results->addError('A', 'format', 'second');
 * $results->addError('B', 'missing', 'third');
 *
 * $perProcessor = array_map(count(...), $results->errors);
 * $firstMessage = array_map(static fn (array $entries): string => $entries[0]['message'], $results->errors);
 * $perKey = [];
 * foreach ($results->errors as $entries) {
 *     foreach ($entries as $entry) {
 *         $perKey[$entry['errorKey']] = ($perKey[$entry['errorKey']] ?? 0) + 1;
 *     }
 * }
 *
 * assert($perProcessor === ['A' => 2, 'B' => 1]);
 * assert($firstMessage === ['A' => 'first', 'B' => 'third']);
 * assert($perKey === ['missing' => 2, 'format' => 1]);
 * ```
 *
 * For logs, prefer identities, keys and counts over messages. A message
 * comes from an exception and can contain the rejected input — an e-mail
 * address, a document number — so log it only where your data-protection
 * policy allows:
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $records = [];
 * $log = static function (string $message, array $context) use (&$records): void {
 *     $records[] = [$message, $context];
 * };
 *
 * $results = new ProcessingResultCollection();
 * $results->recordExecution(EmailValidator::class);
 * $results->addError(EmailValidator::class, 'processingFailed', "'jane@' is not a valid email.");
 *
 * if ($results->hasErrors) {
 *     $log('validation failed', [
 *         'errorCount' => $results->errorCount,
 *         'processors' => array_keys($results->errors),
 *         'trace' => $results->executionTrace,
 *     ]);
 * }
 *
 * assert($records[0][1]['processors'] === [EmailValidator::class]);
 * assert(!str_contains(json_encode($records), 'jane@')); // the input stayed out of the log
 * ```
 *
 * The collection holds only strings in arrays, so `serialize()` round-trips
 * it. Use that only between processes you control: never call
 * `unserialize()` on data that crossed a trust boundary. For anything
 * that leaves the application, map explicitly (§9.6).
 *
 * ```php
 * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
 *
 * $results = new ProcessingResultCollection();
 * $results->addError(EmailValidator::class, 'processingFailed', 'Invalid email.');
 *
 * $copy = unserialize(serialize($results), ['allowed_classes' => [ProcessingResultCollection::class]]);
 *
 * assert($copy instanceof ProcessingResultCollection);
 * assert($copy->errors === $results->errors);
 * ```
 *
 * @since     1.0.0
 *
 * @see \KaririCode\ProcessorPipeline\Handler\ProcessorHandler Records errors and trace
 * @see \KaririCode\ProcessorPipeline\Handler\StageName        Identity resolution
 */
final class ProcessingResultCollection
{
    /** @var array<array-key, list<array{errorKey: string, message: string}>> */
    private array $errorEntries = [];

    /** @var array<array-key, list<array{errorKey: string, message: string}>> */
    private array $warningEntries = [];

    /** @var list<string> Processor execution order for tracing */
    private array $traceEntries = [];

    // ── Property Hooks (PHP 8.4) ─────────────────────────────────────────
    /**
     * Read-only access to collected errors.
     *
     * Grouped by identifier, in order of first entry (§4). Keys are
     * array-key; see §3 for numeric identifiers. The hook has no setter:
     *
     * ```php
     * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
     *
     * $results = new ProcessingResultCollection();
     *
     * $caught = null;
     * try {
     *     $results->errors = [];
     * } catch (\Error $e) {
     *     $caught = $e;
     * }
     * assert($caught !== null); // the collection changes only through its methods
     * ```
     *
     * @var array<array-key, list<array{errorKey: string, message: string}>>
     */
    public array $errors {
        get => $this->errorEntries;
    }

    /**
     * Read-only access to collected warnings.
     *
     * Same shape as $errors; warnings never count towards errorCount or
     * hasErrors.
     *
     * @var array<array-key, list<array{errorKey: string, message: string}>>
     */
    public array $warnings {
        get => $this->warningEntries;
    }

    /**
     * Processors run by handlers, in execution order, failed ones included
     * (C5). A processor that ran twice appears twice.
     *
     * @var list<string>
     */
    public array $executionTrace {
        get => $this->traceEntries;
    }

    /** Whether any errors have been recorded (computed). */
    public bool $hasErrors {
        get => $this->errorEntries !== [];
    }

    /** Whether any warnings have been recorded (computed). */
    public bool $hasWarnings {
        get => $this->warningEntries !== [];
    }

    /**
     * Total error entries across all groups (computed on read, O(g)).
     *
     * ```php
     * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
     *
     * $results = new ProcessingResultCollection();
     * $results->addError('A', 'k1', 'first');
     * $results->addError('A', 'k2', 'second');
     * $results->addError('B', 'k1', 'third');
     *
     * assert($results->errorCount === 3 && count($results->errors) === 2);
     * ```
     */
    public int $errorCount {
        get {
            $count = 0;
            foreach ($this->errorEntries as $entries) {
                $count += count($entries);
            }
            return $count;
        }
    }

    // ── Mutation Methods ─────────────────────────────────────────────────
    /**
     * Record a processing error.
     *
     * Appends to the processor's group; nothing is deduplicated, so the same
     * error recorded twice appears twice (C1). The identifier is stored as
     * given (§3).
     *
     * ```php
     * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
     *
     * $results = new ProcessingResultCollection();
     * $results->addError('App\\Rule', 'same', 'Recorded twice.');
     * $results->addError('App\\Rule', 'same', 'Recorded twice.');
     *
     * assert(count($results->errors['App\\Rule']) === 2); // appended, not deduplicated
     * ```
     *
     * @param string $processorClass Fully-qualified processor class name
     * @param string $errorKey       Machine-readable error identifier
     * @param string $message        Human-readable error description
     */
    public function addError(string $processorClass, string $errorKey, string $message): void
    {
        $this->errorEntries[$processorClass][] = ['errorKey' => $errorKey, 'message' => $message];
    }

    /**
     * Record a processing warning (non-fatal).
     *
     * Warnings never affect hasErrors or errorCount. ProcessorHandler does
     * not record warnings; see §9.5 for where they come from.
     *
     * @param string $processorClass Fully-qualified processor class name
     * @param string $errorKey       Machine-readable warning identifier
     * @param string $message        Human-readable warning description
     */
    public function addWarning(string $processorClass, string $errorKey, string $message): void
    {
        $this->warningEntries[$processorClass][] = ['errorKey' => $errorKey, 'message' => $message];
    }

    /**
     * Record a processor execution for tracing (ARFA P5).
     *
     * ProcessorHandler calls this before running its processor, so the
     * trace also lists processors that failed (C5).
     *
     * @param string $processorClass Fully-qualified processor class name
     */
    public function recordExecution(string $processorClass): void
    {
        $this->traceEntries[] = $processorClass;
    }

    // ── Method accessors (equivalent to the hooks) ───────────────────────
    /**
     * Same as the $errors hook; usable as a callable.
     *
     * @return array<array-key, list<array{errorKey: string, message: string}>>
     */
    public function getErrors(): array
    {
        return $this->errorEntries;
    }

    /**
     * Same as the $warnings hook; usable as a callable.
     *
     * @return array<array-key, list<array{errorKey: string, message: string}>>
     */
    public function getWarnings(): array
    {
        return $this->warningEntries;
    }

    /**
     * Same as the $executionTrace hook; usable as a callable.
     *
     * @return list<string>
     */
    public function getExecutionTrace(): array
    {
        return $this->traceEntries;
    }

    /**
     * Same as the $hasErrors hook; usable as a callable.
     *
     * ```php
     * use KaririCode\ProcessorPipeline\Result\ProcessingResultCollection;
     *
     * $clean = new ProcessingResultCollection();
     * $dirty = new ProcessingResultCollection();
     * $dirty->addError('A', 'k', 'm');
     *
     * $failed = array_filter(
     *     ['clean' => $clean, 'dirty' => $dirty],
     *     static fn (ProcessingResultCollection $results): bool => $results->hasErrors(),
     * );
     *
     * assert(array_keys($failed) === ['dirty']);
     * ```
     */
    public function hasErrors(): bool
    {
        return $this->errorEntries !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warningEntries !== [];
    }

    // ── Collection Operations ────────────────────────────────────────────
    /**
     * Append another collection's errors, warnings and trace to this one.
     *
     * Entries keep their order and are not deduplicated; `$other` is not
     * modified (C4). Typical use: combine per-property collections into a
     * summary (§10).
     */
    public function merge(self $other): void
    {
        foreach ($other->errorEntries as $processor => $entries) {
            foreach ($entries as $entry) {
                $this->errorEntries[$processor][] = $entry;
            }
        }

        foreach ($other->warningEntries as $processor => $entries) {
            foreach ($entries as $entry) {
                $this->warningEntries[$processor][] = $entry;
            }
        }

        $this->traceEntries = [...$this->traceEntries, ...$other->traceEntries];
    }

    /**
     * Remove every error, warning and trace entry.
     *
     * For sequential reuse only (§9.7); never while another run may still
     * be recording into this collection (§12).
     */
    public function reset(): void
    {
        $this->errorEntries = [];
        $this->warningEntries = [];
        $this->traceEntries = [];
    }
}
