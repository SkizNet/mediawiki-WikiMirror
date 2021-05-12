-- This table tracks any pages that have been forked from the mirror wiki,
-- including the import status of the forked page. When a forked page is deleted,
-- it is removed from this table and added to forked_titles instead.
CREATE TABLE /*_*/forked_page (
    -- page_id of the forked page
    fp_page int unsigned NOT NULL PRIMARY KEY,
    -- the remote revision id that we forked,
    -- or NULL if this forked page doesn't have any imported edits
    fp_remote_revision int unsigned,
    -- timestamp of when the page was forked
    fp_forked varbinary(14) NOT NULL,
    -- whether full history import has been completed
    fp_imported tinyint NOT NULL DEFAULT 0,
    -- continuation token used for next batch of history import
    -- will be NULL if import hasn't happened yet
    -- or if import has been completed
    fp_token varbinary(255)
) /*$wgDBTableOptions*/;
