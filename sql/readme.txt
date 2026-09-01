Aauth V3 Database
-----------------

- First open your database (or create one if you have not already done so)
- Execute SQL file "Aauth_v3.sql" in your database
- If you have not already, don't forget to change database connection settings in application/config/database.php

Upgrading from Aauth 2.x
------------------------

- Do not execute "Aauth_v3.sql" over an existing database: it is a complete
  installation schema and drops existing Aauth tables.
- Read "migrations/v2.x-3.x/README.md" and execute the numbered migrations in
  the documented order.

That's All :) 
