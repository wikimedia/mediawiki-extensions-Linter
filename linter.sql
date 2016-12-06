CREATE TABLE /*_*/linter (
	-- primary key
	linter_id int UNSIGNED PRIMARY KEY not null AUTO_INCREMENT,
	-- page id
	linter_page int UNSIGNED not null,
	-- error category (see CategoryManager::$categoryIds)
	linter_cat int UNSIGNED not null,
	-- start and end positions of where the error is located
	linter_start int UNSIGNED not null,
	linter_end int UNSIGNED not null,
	-- extra parameters about the error, JSON encoded
	linter_params blob NOT NULL
) /*$wgDBTableOptions*/;

-- Query by page
CREATE INDEX /*i*/linter_page ON /*_*/linter (linter_page);
-- Unique index for lint errors, also covers linter_cat for query by category
CREATE UNIQUE INDEX /*i*/linter_cat_page_position ON /*_*/linter (linter_cat, linter_page, linter_start, linter_end);
