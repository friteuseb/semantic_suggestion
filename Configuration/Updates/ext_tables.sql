CREATE TABLE tx_semanticsuggestion_nlp_results (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    page_uid int(11) DEFAULT '0' NOT NULL,
    sentiment varchar(255) DEFAULT '' NOT NULL,
    keyphrases text,
    category varchar(255) DEFAULT '' NOT NULL,
    named_entities text,
    readability_score float DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY page (page_uid)
);