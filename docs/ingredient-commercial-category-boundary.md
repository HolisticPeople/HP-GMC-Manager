# Ingredient and commercial-category boundary

The merchant, agent, and OpenAI product feed profiles continue to describe purchasable WooCommerce products. Ingredient taxonomy terms are discovery categories, not purchasable products or a new commerce entity type.

`ProductDataFeed::getProductType` now excludes the exact Dietary Supplements > Ingredients root and every descendant before choosing the deepest remaining commercial category. An unrelated category with the same Ingredients slug is unchanged. Products assigned only to the ingredient branch return an empty optional `product_type`; no commercial category is invented. Product identifiers, availability, checkout eligibility, profile schemas, and feed publication behavior remain unchanged.

The staging audit found real product rows whose prior `product_type` was an ingredient leaf, including Lion's Mane, Quercetin, Alfalfa Grass, and CoQ10. The focused fixture `tests/ProductTypeIngredientBoundaryTest.php` exercises mixed categories, root exclusion, nested descendants, ingredient-only fallback, unrelated same-slug categories, and existing specificity. Identifier parity and profile status isolation remain required checks.

The installed HP-Agent-Gateway exposes its existing authenticated product catalog REST routes and an ACP checkout discovery profile. It does not expose an official UCP catalog profile or protocol. This patch does not add unsupported ingredient fields, enable eligibility, activate crawlers, regenerate a production feed, or advertise an unsupported UCP capability. Catalog/source identity approval remains separately owned.
