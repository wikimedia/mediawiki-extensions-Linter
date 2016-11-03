CREATE TABLE /*_*/lint_categories (
	-- primary key
	lc_id int UNSIGNED PRIMARY KEY not null AUTO_INCREMENT,
	-- category name
	lc_name VARCHAR(30) not null
) /*$wgDBTableOptions*/;

-- Query by name
CREATE UNIQUE INDEX /*i*/lc_name ON /*_*/lint_categories(lc_name);
