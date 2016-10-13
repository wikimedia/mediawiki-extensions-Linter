CREATE TABLE /*_*/linter (
	-- primary key
	linter_id int UNSIGNED AUTO_INCREMENT PRIMARY KEY not null,
	-- page id
	linter_page int UNSIGNED not null,
	-- error category
	linter_cat VARCHAR(30) not null,
	-- extra parameters about the error, JSON encoded
	linter_params blob NOT NULL
) /*$wgDBTableOptions*/;

-- Query by page
CREATE INDEX /*i*/linter_page ON /*_*/linter (linter_page);
