<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Text into vectors.
 *
 * **Our own interface rather than the library's `VectorizerInterface`**, for one analyzer reason and
 * one testing reason. `vectorize()` accepts a union of four input shapes and returns a union of four
 * output shapes, so every call site has to narrow a union before it can use the result — which at
 * this repo's analyzer strictness means several unavoidable errors per caller. And it is `final`, so
 * a test cannot fake it.
 *
 * The narrow contract — a list of strings in, a list of vectors out, same order, same width — is what
 * both callers actually need: ingestion embeds a document's chunks, the tool embeds one question.
 *
 * **Both must use the same model.** A vector answering a question has to come from the model that
 * produced the vectors it is compared against; a mismatch is silent and total, and no test can catch
 * it, which is why the store records its width and refuses a query of another.
 *
 * @api An extension point. Point it at a local model, or at a provider the generic bridge cannot
 *      reach, by replacing the service.
 */
interface Embedder
{
    /**
     * @param list<string> $texts
     *
     * @return list<list<float>> one per text, same order
     *
     * @throws \RuntimeException when the provider cannot be reached or answers with something else
     */
    public function embed(array $texts): array;
}
