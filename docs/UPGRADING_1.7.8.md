# UPGRADING_1.7.8

This upgrade note covers changes introduced in version 1.7.8.

## Summary of Changes Since 1.7.7

- File area rules can be scoped by domain using `TAG@DOMAIN` keys.

## Post upgrade steps

If upgrading from git, be sure to run setup.php after the upgrade. This is to ensure all migrations have been applied. If you upgraded through the installer, this step should have been performed for you.
