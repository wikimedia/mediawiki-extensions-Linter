CREATE TABLE /*_*/linter (
	-- primary key
	linter_id int UNSIGNED PRIMARY KEY not null AUTO_INCREMENT,
	-- page id
	linter_page int UNSIGNED not null,
	-- error category (lint_categories.lc_id)
	linter_cat int UNSIGNED not null,
	-- extra parameters about the error, JSON encoded
	linter_params blob NOT NULL
) /*$wgDBTableOptions*/;

-- Query by page
CREATE INDEX /*i*/linter_page ON /*_*/linter (linter_page);
-- Query by category
CREATE INDEX /*i*/linter_cat ON /*_*/linter (linter_cat);
