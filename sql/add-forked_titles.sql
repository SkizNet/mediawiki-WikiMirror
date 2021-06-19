-- This table tracks pages considered to be "forked" so that we do not fetch
-- it from the mirrored wiki. This table tracks titles that may or may not
-- exist locally; we allow for forking a title but having the page not exist
-- locally in case the remote page needs to be "deleted."
CREATE TABLE /*_*/forked_titles (
    -- Namespace ID
    ft_namespace int NOT NULL,
    -- DB key for the title (without prefix)
    ft_title varbinary(255) NOT NULL,
    -- the remote page id that we forked,
    -- or NULL if this forked page doesn't have any imported edits
    ft_remote_page int unsigned,
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
