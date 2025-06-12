-- Complete migration script for topdata_product_relationships

-- Create the unified table (already done)
CREATE TABLE `topdata_product_relationships`
(
    -- The source product
    `product_id`                BINARY(16)  NOT NULL,
    `product_version_id`        BINARY(16)  NOT NULL,

    -- The destination/linked product (generic column names)
    `linked_product_id`         BINARY(16)  NOT NULL,
    `linked_product_version_id` BINARY(16)  NOT NULL,

    -- The "Discriminator" Column: This is the key to the whole design.
    -- It stores 'similar', 'alternate', 'related', etc.
    `relationship_type`         VARCHAR(50) NOT NULL,

    -- Timestamps
    `created_at`                DATETIME(3) NULL,
    `updated_at`                DATETIME(3) NULL,

    -- The new, powerful PRIMARY KEY ensures a product can't be linked
    -- to the same product with the same relationship type more than once.
    PRIMARY KEY (`product_id`, `linked_product_id`, `relationship_type`),

    -- Index for efficient reverse lookups ("Who links to me?")
    INDEX `idx_linked_product` (`linked_product_id`),

    -- Optional but recommended: Foreign Keys
    CONSTRAINT `fk.product_relationships.product` FOREIGN KEY (`product_id`, `product_version_id`)
        REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.product_relationships.linked_product` FOREIGN KEY (`linked_product_id`, `linked_product_version_id`)
        REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- Migrate data from all relationship tables
SET FOREIGN_KEY_CHECKS = 0;

-- For 'similar'
INSERT INTO topdata_product_relationships (product_id, product_version_id, linked_product_id, linked_product_version_id, relationship_type, created_at, updated_at)
SELECT product_id, product_version_id, similar_product_id, similar_product_version_id, 'similar', created_at, updated_at
FROM topdata_product_to_similar;

-- For 'alternate'
INSERT INTO topdata_product_relationships (product_id, product_version_id, linked_product_id, linked_product_version_id, relationship_type, created_at, updated_at)
SELECT product_id, product_version_id, alternate_product_id, alternate_product_version_id, 'alternate', created_at, updated_at
FROM topdata_product_to_alternate;

-- For 'related'
INSERT INTO topdata_product_relationships (product_id, product_version_id, linked_product_id, linked_product_version_id, relationship_type, created_at, updated_at)
SELECT product_id, product_version_id, related_product_id, related_product_version_id, 'related', created_at, updated_at
FROM topdata_product_to_related;

-- For 'bundled'
INSERT INTO topdata_product_relationships (product_id, product_version_id, linked_product_id, linked_product_version_id, relationship_type, created_at, updated_at)
SELECT product_id, product_version_id, bundled_product_id, bundled_product_version_id, 'bundled', created_at, updated_at
FROM topdata_product_to_bundled;

-- For 'color_variant'
INSERT INTO topdata_product_relationships (product_id, product_version_id, linked_product_id, linked_product_version_id, relationship_type, created_at, updated_at)
SELECT product_id, product_version_id, color_variant_product_id, color_variant_product_version_id, 'color_variant', created_at, updated_at
FROM topdata_product_to_color_variant;

-- For 'capacity_variant'
INSERT INTO topdata_product_relationships (product_id, product_version_id, linked_product_id, linked_product_version_id, relationship_type, created_at, updated_at)
SELECT product_id, product_version_id, capacity_variant_product_id, capacity_variant_product_version_id, 'capacity_variant', created_at, updated_at
FROM topdata_product_to_capacity_variant;

-- For 'variant'
INSERT INTO topdata_product_relationships (product_id, product_version_id, linked_product_id, linked_product_version_id, relationship_type, created_at, updated_at)
SELECT product_id, product_version_id, variant_product_id, variant_product_version_id, 'variant', created_at, updated_at
FROM topdata_product_to_variant;

SET FOREIGN_KEY_CHECKS = 1;




-- Verification queries to check the migration
-- Count records by relationship type
SELECT relationship_type, COUNT(*) as count
FROM topdata_product_relationships
GROUP BY relationship_type;

-- Check total record count matches sum of original tables
SELECT (SELECT COUNT(*) FROM topdata_product_to_similar) +
       (SELECT COUNT(*) FROM topdata_product_to_alternate) +
       (SELECT COUNT(*) FROM topdata_product_to_related) +
       (SELECT COUNT(*) FROM topdata_product_to_bundled) +
       (SELECT COUNT(*) FROM topdata_product_to_color_variant) +
       (SELECT COUNT(*) FROM topdata_product_to_capacity_variant) +
       (SELECT COUNT(*) FROM topdata_product_to_variant)    as original_total,
       (SELECT COUNT(*) FROM topdata_product_relationships) as migrated_total;

-- Optional: Drop the old tables after verifying the migration
-- Uncomment these lines after confirming the migration was successful

-- DROP TABLE topdata_product_to_similar;
-- DROP TABLE topdata_product_to_alternate;
-- DROP TABLE topdata_product_to_related;
-- DROP TABLE topdata_product_to_bundled;
-- DROP TABLE topdata_product_to_color_variant;
-- DROP TABLE topdata_product_to_capacity_variant;
-- DROP TABLE topdata_product_to_variant;


