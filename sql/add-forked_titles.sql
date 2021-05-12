-- Unlike forked_page, this table tracks titles that do *NOT* exist locally
-- but are nevertheless considered to be "forked" so that we do not fetch
-- it from the mirrored wiki either. This can happen if the user forks
-- a page but then deletes it without choosing to re-mirror it. A user can
-- also choose to fork a page into a deleted state rather than importing
-- any revisions. When a page is created locally, the record is deleted
-- from forked_titles and inserted into forked_page.
CREATE TABLE /*_*/forked_titles (
    -- Namespace ID
    ft_namespace int NOT NULL,
    -- DB key for the title (without prefix)
    ft_title varbinary(255) NOT NULL,
    -- the remote revision id that we forked,
    -- or NULL if this forked page doesn't have any imported edits
    ft_remote_revision int unsigned,
    -- timestamp of when the page was forked
    ft_forked varbinary(14) NOT NULL,
    -- whether full history import has been completed
    ft_imported tinyint NOT NULL DEFAULT 0,
    -- continuation token used for next batch of history import
    -- will be NULL if import hasn't happened yet
    -- or if import has been completed
    ft_token varbinary(255),
    PRIMARY KEY (ft_namespace, ft_title)
) /*$wgDBTableOptions*/;
