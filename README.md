# DM Clone Taxonomy

A WordPress plugin which provides a [WP CLI](https://wp-cli.org/) command to clone taxonomy data from one taxonomy to another - copies taxonomy terms, term meta and adds terms to posts of a specified type.

Originally written to enable the creation of a "duplicate" taxonomy to enable a client to re-categorise thousands of e-commerce products on a live site without disruption.

## Usage

`wp clonetax <source_taxonomy_name> <target_taxonomy_name> [--post_type=<post_type_name>] [--skip_meta_keys=<key1,key2>]`

## Notes

Need to empty out your target taxonomy when testing?

`wp term delete target_taxonomy_name $(wp term list target_taxonomy_name --format=ids)`

Supports the `--debug` switch to give more detailed information.

## Todo

- Add support for multiple post types.
- Option to store/output $term_map data.
- Should probably make this a WP CLI package.

---
Built by the team at [Delicious Media](https://www.deliciousmedia.co.uk/), a specialist WordPress development agency based in Sheffield, UK.