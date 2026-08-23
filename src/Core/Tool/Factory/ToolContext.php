<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * What a contributed tool gets by default: somewhere to record what it did, and the merchant's
 * settings.
 *
 * **Deliberately not the gateway or the renderer.** `VISION.md`'s first non-negotiable is that the
 * model is structurally incapable of inventing a price, which holds only while catalogue facts reach
 * it through exactly one path. A tool that could obtain a `ProductCard` could hand the model a raw
 * price, and "structurally" would quietly become "by convention".
 *
 * This is the right context for a store locator, an FAQ lookup, a shipping estimate — anything whose
 * answer is not a catalogue fact. A tool that genuinely needs the catalogue implements
 * {@see GroundedToolFactoryInterface} instead, and takes on the grounding duty its name describes.
 *
 * **Do not add properties here.** `ToolAuthorityTest` asserts this class's exact property list for
 * that reason: widening it is how the guarantee above would end without anyone deciding to end it.
 *
 * @api
 */
final readonly class ToolContext
{
    public function __construct(
        public TraceRecorder $trace,
        public AssistantConfig $config,
    ) {}
}
