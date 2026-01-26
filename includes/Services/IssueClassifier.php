<?php
namespace HP_GMC\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classifies GMC issues into three tiers for appropriate handling.
 * 
 * Tier 1: Fixable - Can be fixed by adding attributes or editing content
 * Tier 2: Misclassified - Likely false positives needing text corrections (DO NOT EXCLUDE)
 * Tier 3: Restriction - True policy restrictions requiring exclusion feeds
 */
class IssueClassifier
{
    // Issue classification tiers
    const TIER_FIXABLE = 'fixable';
    const TIER_MISCLASSIFIED = 'misclassified';
    const TIER_RESTRICTION = 'restriction';

    // Review statuses for misclassified products
    const REVIEW_PENDING = 'pending_review';
    const REVIEW_FIX_APPLIED = 'fix_applied';
    const REVIEW_AWAITING_RECRAWL = 'awaiting_recrawl';
    const REVIEW_MARKED_RESTRICTION = 'marked_restriction';

    /**
     * Patterns for Tier 1: Fixable Issues (content/data changes)
     * These can be fixed by adding missing attributes or editing content.
     */
    const FIXABLE_PATTERNS = [
        'Missing.*unit pricing' => [
            'type' => 'attribute',
            'fix' => 'Add unit_pricing_measure and unit_pricing_base_measure attributes',
            'attribute' => 'unit_pricing_measure',
        ],
        'Missing.*age.?group' => [
            'type' => 'attribute',
            'fix' => 'Set age_group to "adult" (for supplements)',
            'attribute' => 'age_group',
            'suggested_value' => 'adult',
        ],
        'Missing.*gender' => [
            'type' => 'attribute',
            'fix' => 'Set gender to "unisex"',
            'attribute' => 'gender',
            'suggested_value' => 'unisex',
        ],
        'Missing.*color' => [
            'type' => 'attribute',
            'fix' => 'Extract color from product name or set to N/A',
            'attribute' => 'color',
        ],
        'Missing.*shipping.*weight' => [
            'type' => 'woocommerce',
            'fix' => 'Set product weight in WooCommerce',
            'field' => 'weight',
        ],
        'Invalid.*title' => [
            'type' => 'text',
            'fix' => 'Remove promotional text, ensure title is 1-150 characters',
            'field' => 'title',
        ],
        'Title.*too.*long' => [
            'type' => 'text',
            'fix' => 'Shorten title to under 150 characters',
            'field' => 'title',
        ],
        'Invalid.*image' => [
            'type' => 'image',
            'fix' => 'Ensure image has white background, proper size, no watermarks',
            'field' => 'image',
        ],
        'Image.*too.*small' => [
            'type' => 'image',
            'fix' => 'Use image with minimum 100x100 pixels (250x250 for apparel)',
            'field' => 'image',
        ],
        'Promotional.*overlay' => [
            'type' => 'image',
            'fix' => 'Remove promotional overlays, watermarks, or text from image',
            'field' => 'image',
        ],
        'False or misleading' => [
            'type' => 'text',
            'fix' => 'Remove health claims, use compliant language',
            'field' => 'description',
        ],
        'misleading claims' => [
            'type' => 'text',
            'fix' => 'Remove health claims, use compliant language',
            'field' => 'description',
            'trigger_keywords' => ['guaranteed', 'cure', 'treat', 'prevent', 'shield', '5g', 'tachyon', 'protection', 'healing'],
        ],
        'Unsubstantiated.*claim' => [
            'type' => 'text',
            'fix' => 'Remove or substantiate health claims',
            'field' => 'description',
        ],
        'Missing.*GTIN' => [
            'type' => 'attribute',
            'fix' => 'Add GTIN/UPC/EAN barcode or set identifier_exists to false',
            'attribute' => 'gtin',
        ],
        'Missing.*brand' => [
            'type' => 'attribute',
            'fix' => 'Add brand name or set to store name for own-brand products',
            'attribute' => 'brand',
        ],
    ];

    /**
     * Patterns for Tier 2: Misclassifications (false positives - DO NOT EXCLUDE)
     * The store does not sell these products; Google misidentified them.
     * These need text corrections to avoid triggering the wrong category.
     */
    const MISCLASSIFIED_PATTERNS = [
        'Prescription.*drug' => [
            'likely_cause' => 'Medical terminology in description triggered prescription drug detection',
            'fix_strategy' => 'Remove or rephrase medical terminology that implies prescription status',
            'trigger_keywords' => ['prescription', 'Rx', 'doctor prescribed', 'pharmaceutical', 'drug'],
        ],
        'Behind.*counter.*drug' => [
            'likely_cause' => 'Product description implies pharmacy-controlled item',
            'fix_strategy' => 'Remove references to pharmacies or medication dispensing',
            'trigger_keywords' => ['pharmacist', 'counter', 'dispensing'],
        ],
        'Dangerous.*product.*tobacco' => [
            'likely_cause' => 'False positive - store does not sell tobacco products',
            'fix_strategy' => 'Remove any tobacco-related keywords from title/description',
            'trigger_keywords' => ['tobacco', 'nicotine', 'cigarette', 'vape', 'smoking'],
        ],
        'Dangerous.*product.*recreational' => [
            'likely_cause' => 'Supplement terminology misinterpreted as recreational drug',
            'fix_strategy' => 'Use compliant supplement language, avoid drug-like claims',
            'trigger_keywords' => ['high', 'buzz', 'euphoria', 'psychoactive', 'trip'],
        ],
        'Dangerous.*product.*drug' => [
            'likely_cause' => 'Product language interpreted as controlled substance',
            'fix_strategy' => 'Reword to emphasize dietary supplement nature',
            'trigger_keywords' => ['drug', 'narcotic', 'controlled', 'substance'],
        ],
        'Weapon' => [
            'likely_cause' => 'Product may contain knife/tool terminology',
            'fix_strategy' => 'Clarify product is not a weapon (kitchen tool, etc.)',
            'trigger_keywords' => ['knife', 'blade', 'sharp', 'weapon', 'tactical'],
        ],
    ];

    /**
     * Patterns for Tier 3: True Policy Restrictions (require exclusions)
     * These are products that genuinely fall under restricted categories.
     * For health/supplement stores, these are expected and require feed exclusions.
     */
    const RESTRICTION_PATTERNS = [
        'Personal.*Hardship' => [
            'exclusions' => ['Display_ads', 'Video_ads'],
            'feed_category' => 'personalization',
            'reason' => 'Health/supplement products flagged for personalization restrictions',
        ],
        'Prohibited.*pharmaceuticals' => [
            'exclusions' => ['Shopping_ads', 'Display_ads'],
            'feed_category' => 'pharma',
            'reason' => 'Supplements classified under pharmaceutical policies',
        ],
        'Prohibited.*supplement' => [
            'exclusions' => ['Shopping_ads', 'Display_ads'],
            'feed_category' => 'pharma',
            'reason' => 'Supplements with restricted ingredients or claims',
        ],
        'Over.*counter.*medication' => [
            'exclusions' => ['Shopping_ads'],
            'feed_category' => 'otc',
            'reason' => 'Products classified as OTC medication',
        ],
        'OTC' => [
            'exclusions' => ['Shopping_ads'],
            'feed_category' => 'otc',
            'reason' => 'Products classified as OTC medication',
        ],
        'Pet.*pharmaceutical' => [
            'exclusions' => ['Shopping_ads'],
            'feed_category' => 'otc',
            'reason' => 'Pet health products under pharmaceutical policies',
        ],
        'Sexual.*interest' => [
            'exclusions' => ['Display_ads', 'Video_ads'],
            'feed_category' => 'personalization',
            'reason' => 'Products flagged for personalization restrictions',
        ],
        'Adult.*content' => [
            'exclusions' => ['Display_ads', 'Video_ads'],
            'feed_category' => 'personalization',
            'reason' => 'Products flagged for adult content restrictions',
        ],
    ];

    /**
     * Classify an issue description into a tier.
     */
    public static function classifyIssue(string $issueDescription): array
    {
        $description = strtolower($issueDescription);

        // Check Tier 1: Fixable first (most common)
        foreach (self::FIXABLE_PATTERNS as $pattern => $config) {
            if (preg_match('/' . strtolower($pattern) . '/i', $description)) {
                return [
                    'tier' => self::TIER_FIXABLE,
                    'issue' => $issueDescription,
                    'pattern' => $pattern,
                    'fix_type' => $config['type'],
                    'suggested_fix' => $config['fix'],
                    'details' => $config,
                ];
            }
        }

        // Check Tier 2: Misclassified (false positives)
        foreach (self::MISCLASSIFIED_PATTERNS as $pattern => $config) {
            if (preg_match('/' . strtolower($pattern) . '/i', $description)) {
                return [
                    'tier' => self::TIER_MISCLASSIFIED,
                    'issue' => $issueDescription,
                    'pattern' => $pattern,
                    'likely_cause' => $config['likely_cause'],
                    'fix_strategy' => $config['fix_strategy'],
                    'trigger_keywords' => $config['trigger_keywords'],
                    'action' => 'review_text', // DO NOT EXCLUDE
                ];
            }
        }

        // Check Tier 3: True Restrictions
        foreach (self::RESTRICTION_PATTERNS as $pattern => $config) {
            if (preg_match('/' . strtolower($pattern) . '/i', $description)) {
                return [
                    'tier' => self::TIER_RESTRICTION,
                    'issue' => $issueDescription,
                    'pattern' => $pattern,
                    'exclusions' => $config['exclusions'],
                    'feed_category' => $config['feed_category'],
                    'reason' => $config['reason'],
                    'action' => 'add_to_feed',
                ];
            }
        }

        // Unknown issue - default to fixable for manual review
        return [
            'tier' => self::TIER_FIXABLE,
            'issue' => $issueDescription,
            'pattern' => null,
            'fix_type' => 'manual',
            'suggested_fix' => 'Review manually and determine appropriate action',
            'details' => [],
        ];
    }

    /**
     * Classify all issues for a product and return the most severe tier.
     */
    public static function classifyProduct(array $issues): array
    {
        $classifications = [];
        $tiers = [
            self::TIER_FIXABLE => [],
            self::TIER_MISCLASSIFIED => [],
            self::TIER_RESTRICTION => [],
        ];

        foreach ($issues as $issue) {
            $description = $issue['description'] ?? (is_string($issue) ? $issue : '');
            if (empty($description)) {
                continue;
            }

            $classification = self::classifyIssue($description);
            $classifications[] = $classification;
            $tiers[$classification['tier']][] = $classification;
        }

        // Determine primary tier (restriction > misclassified > fixable)
        $primaryTier = self::TIER_FIXABLE;
        if (!empty($tiers[self::TIER_RESTRICTION])) {
            $primaryTier = self::TIER_RESTRICTION;
        } elseif (!empty($tiers[self::TIER_MISCLASSIFIED])) {
            $primaryTier = self::TIER_MISCLASSIFIED;
        }

        return [
            'primary_tier' => $primaryTier,
            'classifications' => $classifications,
            'by_tier' => $tiers,
            'counts' => [
                'fixable' => count($tiers[self::TIER_FIXABLE]),
                'misclassified' => count($tiers[self::TIER_MISCLASSIFIED]),
                'restriction' => count($tiers[self::TIER_RESTRICTION]),
            ],
        ];
    }

    /**
     * Get products grouped by tier from the status cache.
     */
    public static function getProductsByTier(?string $tier = null, int $limit = 100): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';

        // Filter out invalid rows (product_id = 0 or NULL) - these are data quality issues
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE status IN ('disapproved', 'warning') AND product_id > 0 ORDER BY last_updated DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        $grouped = [
            self::TIER_FIXABLE => [],
            self::TIER_MISCLASSIFIED => [],
            self::TIER_RESTRICTION => [],
        ];

        foreach ($results as $row) {
            $issues = json_decode($row['issues'], true) ?: [];
            $classification = self::classifyProduct($issues);
            
            $row['classification'] = $classification;
            $row['issues_parsed'] = $issues;
            
            // Get product details
            $product = wc_get_product($row['product_id']);
            $row['product_name'] = $product ? $product->get_name() : 'Unknown Product';
            $row['sku'] = $product ? $product->get_sku() : '';
            $row['edit_url'] = $product ? get_edit_post_link($row['product_id'], 'raw') : '';
            
            $grouped[$classification['primary_tier']][] = $row;
        }

        if ($tier !== null && isset($grouped[$tier])) {
            return $grouped[$tier];
        }

        return $grouped;
    }

    /**
     * Get issue summary with counts by tier and destination.
     */
    public static function getIssueSummary(): array
    {
        $products = self::getProductsByTier(null, 1000);
        
        $summary = [
            'total_products' => 0,
            'by_tier' => [
                self::TIER_FIXABLE => ['count' => 0, 'issues' => []],
                self::TIER_MISCLASSIFIED => ['count' => 0, 'issues' => []],
                self::TIER_RESTRICTION => ['count' => 0, 'issues' => []],
            ],
            'by_destination' => [],
        ];

        foreach ($products as $tier => $tierProducts) {
            $summary['by_tier'][$tier]['count'] = count($tierProducts);
            $summary['total_products'] += count($tierProducts);
            
            // Collect issue types and destination stats
            foreach ($tierProducts as $product) {
                // Issues
                foreach ($product['classification']['classifications'] as $classification) {
                    $issue = $classification['issue'];
                    if (!isset($summary['by_tier'][$tier]['issues'][$issue])) {
                        $summary['by_tier'][$tier]['issues'][$issue] = 0;
                    }
                    $summary['by_tier'][$tier]['issues'][$issue]++;
                }

                // Destinations
                $destinations = json_decode($product['destinations'] ?? '[]', true) ?: [];
                foreach ($destinations as $dest) {
                    $ctx = $dest['context'] ?? 'unknown';
                    if (!isset($summary['by_destination'][$ctx])) {
                        $summary['by_destination'][$ctx] = [
                            'total' => 0,
                            'approved' => 0,
                            'disapproved' => 0,
                        ];
                    }
                    $summary['by_destination'][$ctx]['total']++;
                    if (!empty($dest['approved_countries'])) {
                        $summary['by_destination'][$ctx]['approved']++;
                    } else {
                        $summary['by_destination'][$ctx]['disapproved']++;
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * Analyze product text for trigger keywords (for misclassified products).
     */
    public static function analyzeTriggers(int $productId, array $triggerKeywords): array
    {
        $product = wc_get_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found'];
        }

        $title = $product->get_name();
        $description = $product->get_description();
        $shortDescription = $product->get_short_description();
        
        $fullText = strtolower($title . ' ' . $description . ' ' . $shortDescription);
        
        $found = [];
        foreach ($triggerKeywords as $keyword) {
            $keyword = strtolower($keyword);
            if (strpos($fullText, $keyword) !== false) {
                // Find context around the keyword
                $pos = strpos($fullText, $keyword);
                $start = max(0, $pos - 50);
                $end = min(strlen($fullText), $pos + strlen($keyword) + 50);
                $context = substr($fullText, $start, $end - $start);
                
                $found[] = [
                    'keyword' => $keyword,
                    'context' => '...' . $context . '...',
                    'location' => strpos(strtolower($title), $keyword) !== false ? 'title' :
                                  (strpos(strtolower($shortDescription), $keyword) !== false ? 'short_description' : 'description'),
                ];
            }
        }

        return [
            'success' => true,
            'product_id' => $productId,
            'product_name' => $title,
            'triggers_found' => $found,
            'trigger_count' => count($found),
        ];
    }

    /**
     * Generate AI-suggested text fix for a misclassified product.
     */
    public static function suggestTextFix(int $productId, string $issueDescription): array
    {
        $classification = self::classifyIssue($issueDescription);
        
        // Allow suggest fix for misclassified OR specific fixable text issues
        $allowedFixable = ['False or misleading', 'Unsubstantiated.*claim', 'Invalid.*title'];
        $isAllowedFixable = false;
        if ($classification['tier'] === self::TIER_FIXABLE && $classification['pattern']) {
            foreach ($allowedFixable as $pattern) {
                if (preg_match('/' . $pattern . '/i', $classification['pattern'])) {
                    $isAllowedFixable = true;
                    break;
                }
            }
        }

        if ($classification['tier'] !== self::TIER_MISCLASSIFIED && !$isAllowedFixable) {
            return [
                'success' => false,
                'error' => 'This issue is not eligible for text fix suggestions. Tier: ' . $classification['tier'],
                'classification' => $classification,
            ];
        }

        $product = wc_get_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found'];
        }

        // Use trigger keywords from classification or default to some health-related ones if fixable
        $keywords = $classification['trigger_keywords'] ?? ['guaranteed', 'cure', 'treat', 'prevent', 'shield', '5g', 'tachyon'];
        
        // Analyze which triggers are present
        $triggerAnalysis = self::analyzeTriggers($productId, $keywords);
        
        if (!$triggerAnalysis['success']) {
            return $triggerAnalysis;
        }
        
        if (empty($triggerAnalysis['triggers_found'])) {
            return [
                'success' => true,
                'product_id' => $productId,
                'product_name' => $product->get_name(),
                'message' => 'No specific trigger keywords found in title or description, but you should review for general health claims.',
                'classification' => $classification,
                'suggestions' => [],
            ];
        }

        $suggestions = [];
        foreach ($triggerAnalysis['triggers_found'] as $trigger) {
            $suggestions[] = [
                'keyword' => $trigger['keyword'],
                'location' => $trigger['location'],
                'context' => $trigger['context'],
                'suggestion' => self::getSafeAlternative($trigger['keyword']),
            ];
        }

        return [
            'success' => true,
            'product_id' => $productId,
            'product_name' => $product->get_name(),
            'issue' => $issueDescription,
            'likely_cause' => $classification['likely_cause'] ?? 'Language triggering policy flags',
            'fix_strategy' => $classification['fix_strategy'] ?? 'Replace or remove problematic keywords',
            'triggers_found' => $triggerAnalysis['triggers_found'],
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Batch fix fixable attributes for a list of products.
     * Only applies fixes where a suggested_value is defined in FIXABLE_PATTERNS.
     */
    public static function batchFixFixableAttributes(array $productIds): array
    {
        $results = [
            'total' => count($productIds),
            'fixed' => 0,
            'skipped' => 0,
            'errors' => [],
            'details' => [],
        ];

        foreach ($productIds as $productId) {
            try {
                $statusRow = self::getProductStatusById($productId);
                if (!$statusRow) {
                    $results['skipped']++;
                    continue;
                }

                $issues = json_decode($statusRow['issues'], true) ?: [];
                $classification = self::classifyProduct($issues);
                
                $fixedCount = 0;
                $appliedFixes = [];

                foreach ($classification['classifications'] as $c) {
                    if ($c['tier'] === self::TIER_FIXABLE && isset($c['details']['suggested_value'])) {
                        $attr = $c['details']['attribute'];
                        $val = $c['details']['suggested_value'];
                        
                        // Apply attribute fix via WooCommerce meta
                        // For GLA/GMC, these are often stored in specific meta keys
                        $metaKey = '_wc_gla_' . $attr; 
                        update_post_meta($productId, $metaKey, $val);
                        
                        $appliedFixes[] = [
                            'attribute' => $attr,
                            'value' => $val,
                        ];
                        $fixedCount++;
                    }
                }

                if ($fixedCount > 0) {
                    $results['fixed']++;
                    $results['details'][] = [
                        'product_id' => $productId,
                        'fixes' => $appliedFixes,
                    ];
                    
                    AuditLog::log('batch_attribute_fix', [
                        'product_id' => $productId,
                        'fixes' => $appliedFixes,
                    ], ['success' => true]);
                } else {
                    $results['skipped']++;
                }

            } catch (\Exception $e) {
                $results['errors'][] = "Product {$productId}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get product status row by WC product ID.
     */
    private static function getProductStatusById(int $productId): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hp_gmc_product_status';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE product_id = %d", $productId), ARRAY_A);
    }

    /**
     * Get safe alternative phrasing for a trigger keyword.
     */
    private static function getSafeAlternative(string $keyword): string
    {
        $alternatives = [
            'prescription' => 'recommended by healthcare professionals',
            'rx' => 'professional-grade',
            'drug' => 'supplement',
            'pharmaceutical' => 'wellness product',
            'medication' => 'dietary supplement',
            'tobacco' => '[remove - product is not tobacco]',
            'nicotine' => '[remove - product contains no nicotine]',
            'vape' => '[remove if not applicable]',
            'smoking' => '[remove or rephrase]',
            'high' => 'potent / effective',
            'buzz' => 'energy',
            'euphoria' => 'wellness / balance',
            'psychoactive' => 'bioactive',
            'trip' => '[remove]',
            'narcotic' => '[remove]',
            'controlled' => 'quality-controlled',
            'substance' => 'ingredient / compound',
        ];

        $keyword = strtolower($keyword);
        return $alternatives[$keyword] ?? "Review and rephrase '{$keyword}'";
    }

    /**
     * Apply a text fix to a product (updates WooCommerce).
     */
    public static function applyTextFix(int $productId, string $field, string $oldText, string $newText): array
    {
        $product = wc_get_product($productId);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found'];
        }

        $originalValue = '';
        
        switch ($field) {
            case 'title':
            case 'name':
                $originalValue = $product->get_name();
                $updatedValue = str_replace($oldText, $newText, $originalValue);
                $product->set_name($updatedValue);
                break;
                
            case 'description':
                $originalValue = $product->get_description();
                $updatedValue = str_replace($oldText, $newText, $originalValue);
                $product->set_description($updatedValue);
                break;
                
            case 'short_description':
                $originalValue = $product->get_short_description();
                $updatedValue = str_replace($oldText, $newText, $originalValue);
                $product->set_short_description($updatedValue);
                break;
                
            default:
                return ['success' => false, 'error' => 'Invalid field: ' . $field];
        }

        $product->save();

        // Track the fix in product meta
        $fixes = get_post_meta($productId, '_hp_gmc_text_fixes', true) ?: [];
        $fixes[] = [
            'field' => $field,
            'old_text' => $oldText,
            'new_text' => $newText,
            'applied_at' => current_time('mysql'),
            'applied_by' => get_current_user_id(),
        ];
        update_post_meta($productId, '_hp_gmc_text_fixes', $fixes);
        
        // Set review status
        update_post_meta($productId, '_hp_gmc_review_status', self::REVIEW_FIX_APPLIED);

        // Log to audit
        AuditLog::log('text_fix_applied', [
            'product_id' => $productId,
            'field' => $field,
            'old_text' => $oldText,
            'new_text' => $newText,
        ], ['success' => true]);

        return [
            'success' => true,
            'product_id' => $productId,
            'field' => $field,
            'original_value' => $originalValue,
            'updated_value' => $updatedValue,
        ];
    }

    /**
     * Mark a misclassified product as a true restriction (move to Tier 3).
     */
    public static function markAsRestriction(int $productId, string $reason): array
    {
        update_post_meta($productId, '_hp_gmc_review_status', self::REVIEW_MARKED_RESTRICTION);
        update_post_meta($productId, '_hp_gmc_marked_restriction_reason', $reason);
        update_post_meta($productId, '_hp_gmc_marked_restriction_at', current_time('mysql'));

        AuditLog::log('marked_as_restriction', [
            'product_id' => $productId,
            'reason' => $reason,
        ], ['success' => true]);

        return [
            'success' => true,
            'product_id' => $productId,
            'message' => 'Product marked as true restriction. It will now appear in Tier 3 for exclusion feed assignment.',
        ];
    }

    /**
     * Get review status for a product.
     */
    public static function getReviewStatus(int $productId): ?string
    {
        return get_post_meta($productId, '_hp_gmc_review_status', true) ?: null;
    }

    /**
     * Get all products pending review.
     */
    public static function getProductsPendingReview(): array
    {
        $misclassified = self::getProductsByTier(self::TIER_MISCLASSIFIED, 500);
        
        return array_filter($misclassified, function($product) {
            $status = self::getReviewStatus($product['product_id']);
            return $status === null || $status === self::REVIEW_PENDING;
        });
    }
}
