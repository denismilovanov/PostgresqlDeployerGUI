PostgresqlDeployerGUI
=====================

### Intro

PostgresqlDeployerGUI provides you web interface that simplifies deployment of PostgreSQL schema.

Consider single PostgreSQL database. Suppose that we are to deploy some schema changes.
There are several approaches.

Popular approach is to use migrations. You take your favorite migration tool built in your framework,
write 'up' code, write 'down' code, and you are done.
Advantages are obvious: one after you can deploy schema changes to any machine (local, staging, production) very easily.

But I see 2 problems here:

1) migrations like `ALTER TABLE ADD COLUMN NOT NULL DEFAULT` on huge tables have to be performed manually in any case
(fast `ALTER TABLE ADD COLUMN NULL` first, then `ALTER TABLE ALTER COLUMN SET DEFAULT`, then batch updates of null values),

2) stored functions and types up and down migrations lead to great code overhead (to add one line in function you have to double
its source code, to change function signature - you have to drop previous version).

That is why I don't believe in completely automatic migrations. They are suiteable only for small projects.

Git (where I offer to store schema) is already migration-fashioned system. You may say `git checkout <commit_hash>` and
get schema state at any time in the past to rollback if it is needed, or say `git pull` and see the difference between states
(if you organize database objects storage).

PostgreSQLDeployerGUI works with 4 database objects types:
* tables,
* seeds (data of system dictionaries, mappings, settings, etc),
* types (PostgreSQL user types),
* functions (PostgreSQL stored procedures).

Tables DDL's (`CREATE TABLE`, `CREATE INDEX CONCURRENTLY`, `ALTER TABLE`) are committed into git and deployed always in manual mode.
Interface just shows you table difference between current schema in git and your actual schema.

Seeds are deployed automatically via `DELETE - INSERT`.

Types are deployed automatically by dropping old version of type with all dependent functions.
Interface will show you dependencies, they will be included into deployment list.

Functions are deployed automatically by dropping old version if signature or return type were changed.
Then `CREATE OR REPLACE FUNCTION` is called.

All changes are deployed in single transaction. You may exclude any object from list.

### Installation

Clone repository:

    git clone https://github.com/denismilovanov/PostgresqlDeployerGUI.git

Setup your web server:

    http://silex.sensiolabs.org/doc/web_servers.html

Perform on your database (psql):

    \i db.schema/create_schema.sql
    
Add new user (psql will show how).

Change config file:

    nano libs/databases.json
    
Example:

    {
        "databases": {
            "db1": {
                "index": "db1",
                "name": "DB 1",
                "credentials": {
                    "host": "localhost",
                    "port": "5432",
                    "user_name": "user1",
                    "user_password": "pass1",
                    "db_name": "db1"
                },
                "git_root": "/home/user/work/project/db1_git_root/"
            },
            "db2": {
                "index": "db2",
                "name": "DB 2",
                "credentials": {
                    "host": "localhost",
                    "port": "5432",
                    "user_name": "user2",
                    "user_password": "pass2",
                    "db_name": "db2"
                },
                "git_root": "/home/user/work/project/db2_git_root/"
            }
        }
    }


### Repository

Your schema repository should have structure like this:

    repository_root/
        schemas/
            schema1/
                functions/
                    function_name1.sql
                    function_name2.sql
                        ...
                seeds/
                    seed_table_name1.sql
                    seed_table_name2.sql
                    ...
                tables/
                    table_name1.sql
                    table_name2.sql
                    ...
                types/
                    type_name1.sql
                    type_name2.sql
                    ...
            schema2/
                functions/
                seeds/
                tables/
                types/
            ...
        other_directories/
        ...

### Seeds

Expected structure of seed file `seed_table_name.sql`:

    BEGIN;

    DELETE FROM seed_table_name;

    INSERT INTO seed_table_name (...)
        VALUES (...);

    COMMIT;

Please, do not use `TRUNCATE`, it is not MVCC safe.

If you use foreign key referenced to seed table you should make it `DERERRABLE INITIALLY DEFERRED`, in other case `DELETE FROM` will throw an error.

This structure allows you to deploy seed in manual mode with single transaction via simple copying the code.

### Stored functions (procedures)

Expected structure of function file `function_name.sql` (usual pg `CREATE OR REPLACE FUNCTION`):

    CREATE OR REPLACE FUNCTION function_name(
        <params>
    )
        RETURNS ret_value_type AS
    $BODY$
    DECLARE
    BEGIN
        <body>
    END
    $BODY$
        LANGUAGE plpgsql;


These cases related to functions currently are not supported:

1. Trigger functions.
2. Functions indexes are based on.
3. Functions overloading. It is not allowed to have more than one procedures with the same or different names in one file.

When you use custom type name you should also provide schema name (even if schema is in `search_path`):

    DECLARE
        i_count integer;
        r_element queues.t_queue_element;

### Types

Expected structure of type file `type_name.sql` (usual pg `CREATE TYPE`):

    CREATE TYPE type_name AS (
        <description>
    );
