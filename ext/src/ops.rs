use bson::Document;
use bson::raw::RawDocumentBuf;
use mongodb::Client;

use crate::coroutine;

pub fn find_one(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move { collection.find_one(filter).await })
}

pub fn find(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<mongodb::Cursor<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move { collection.find(filter).await })
}

pub fn insert_one(
    client: &Client,
    db: &str,
    col: &str,
    doc: Document,
) -> Result<mongodb::results::InsertOneResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.insert_one(doc).await })
}

pub fn update_one(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
    update: Document,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.update_one(filter, update).await })
}

pub fn update_many(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
    update: Document,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.update_many(filter, update).await })
}

pub fn delete_one(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<mongodb::results::DeleteResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.delete_one(filter).await })
}

pub fn delete_many(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<mongodb::results::DeleteResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.delete_many(filter).await })
}

pub fn replace_one(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
    replacement: Document,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.replace_one(filter, replacement).await })
}

pub fn count_documents(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<u64, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.count_documents(filter).await })
}

pub fn distinct(
    client: &Client,
    db: &str,
    col: &str,
    field_name: &str,
    filter: Document,
) -> Result<Vec<bson::Bson>, String> {
    let collection = client.database(db).collection::<Document>(col);
    let field = field_name.to_string();
    coroutine::run_sync(async move { collection.distinct(&field, filter).await })
}

pub fn aggregate(
    client: &Client,
    db: &str,
    col: &str,
    pipeline: Vec<Document>,
) -> Result<mongodb::Cursor<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.aggregate(pipeline).await })
}

pub fn find_one_and_update(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
    update: Document,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move { collection.find_one_and_update(filter, update).await })
}

pub fn find_one_and_delete(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move { collection.find_one_and_delete(filter).await })
}

pub fn create_index(
    client: &Client,
    db: &str,
    col: &str,
    keys: Document,
    options_doc: Option<Document>,
) -> Result<String, String> {
    let collection = client.database(db).collection::<Document>(col);
    let mut idx_opts = mongodb::options::IndexOptions::default();
    if let Some(ref opts) = options_doc {
        if let Ok(name) = opts.get_str("name") {
            idx_opts.name = Some(name.to_string());
        }
        if let Ok(unique) = opts.get_bool("unique") {
            idx_opts.unique = Some(unique);
        }
        if let Ok(sparse) = opts.get_bool("sparse") {
            idx_opts.sparse = Some(sparse);
        }
        if let Ok(background) = opts.get_bool("background") {
            idx_opts.background = Some(background);
        }
        if let Ok(expire) = opts.get_i64("expireAfterSeconds") {
            idx_opts.expire_after = Some(std::time::Duration::from_secs(expire as u64));
        } else if let Ok(expire) = opts.get_i32("expireAfterSeconds") {
            idx_opts.expire_after = Some(std::time::Duration::from_secs(expire as u64));
        }
        // Previously dropped index options (#16, #57): without these a partial
        // index covered the whole collection, a hidden index stayed visible, a
        // text index lost its weights/default_language, and wildcard/storage
        // options never reached the server.
        if let Ok(pfe) = opts.get_document("partialFilterExpression") {
            idx_opts.partial_filter_expression = Some(pfe.clone());
        }
        if let Ok(hidden) = opts.get_bool("hidden") {
            idx_opts.hidden = Some(hidden);
        }
        if let Ok(weights) = opts.get_document("weights") {
            idx_opts.weights = Some(weights.clone());
        }
        if let Ok(lang) = opts.get_str("default_language") {
            idx_opts.default_language = Some(lang.to_string());
        }
        if let Ok(wp) = opts.get_document("wildcardProjection") {
            idx_opts.wildcard_projection = Some(wp.clone());
        }
        if let Ok(se) = opts.get_document("storageEngine") {
            idx_opts.storage_engine = Some(se.clone());
        }
    }
    let index_model = mongodb::IndexModel::builder().keys(keys).options(idx_opts).build();
    coroutine::run_sync(async move {
        match collection.create_index(index_model).await {
            Ok(r) => Ok(r.index_name),
            Err(e) => {
                let err_str = e.to_string();
                if err_str.contains("IndexOptionsConflict") || err_str.contains("already exists") {
                    Ok("_existing".to_string())
                } else {
                    Err(e)
                }
            }
        }
    })
}

pub fn find_one_and_replace(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
    replacement: Document,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        match collection.find_one_and_replace(filter, replacement).await
            .map_err(|e| mongodb::error::Error::custom(e))?
        {
            Some(doc) => {
                let raw = RawDocumentBuf::from_document(&doc)
                    .map_err(|e| mongodb::error::Error::custom(e))?;
                Ok::<_, mongodb::error::Error>(Some(raw))
            }
            None => Ok(None),
        }
    })
}

pub fn list_databases(client: &Client) -> Result<Vec<String>, String> {
    let client = client.clone();
    coroutine::run_sync(async move {
        client.list_database_names().await
    })
}

pub fn insert_many(
    client: &Client, db: &str, col: &str, docs: Vec<Document>, opts: mongodb::options::InsertManyOptions,
) -> Result<mongodb::results::InsertManyResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.insert_many(docs).with_options(opts).await })
}

pub fn estimated_document_count(
    client: &Client, db: &str, col: &str,
) -> Result<u64, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.estimated_document_count().await })
}

pub fn drop_collection(client: &Client, db: &str, col: &str) -> Result<(), String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.drop().await })
}

pub fn list_indexes(client: &Client, db: &str, col: &str) -> Result<Vec<Document>, String> {
    // Return the raw server index specs instead of re-projecting a parsed
    // IndexModel down to key/name/unique/sparse. Re-projecting dropped
    // expireAfterSeconds, v, partialFilterExpression, collation, weights,
    // hidden, etc. (#17). The listIndexes command's cursor.firstBatch already
    // carries every field the official driver surfaces.
    let database = client.database(db);
    let col = col.to_string();
    coroutine::run_sync(async move {
        let result = database.run_command(bson::doc! { "listIndexes": &col }).await?;
        let indexes = result
            .get_document("cursor")
            .ok()
            .and_then(|c| c.get_array("firstBatch").ok())
            .map(|arr| {
                arr.iter()
                    .filter_map(|b| b.as_document().cloned())
                    .collect::<Vec<Document>>()
            })
            .unwrap_or_default();
        Ok::<Vec<Document>, mongodb::error::Error>(indexes)
    })
}

pub fn drop_index(client: &Client, db: &str, col: &str, name: &str) -> Result<(), String> {
    let collection = client.database(db).collection::<Document>(col);
    let name_s = name.to_string();
    coroutine::run_sync(async move { collection.drop_index(name_s).await.map(|_| ()) })
}

pub fn drop_indexes(client: &Client, db: &str, col: &str) -> Result<(), String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.drop_indexes().await.map(|_| ()) })
}

pub fn run_command(client: &Client, db: &str, command: Document) -> Result<Document, String> {
    let database = client.database(db);
    coroutine::run_sync(async move { database.run_command(command).await })
}

pub fn create_collection(client: &Client, db: &str, name: &str) -> Result<(), String> {
    let database = client.database(db);
    let name_s = name.to_string();
    coroutine::run_sync(async move { database.create_collection(name_s).await.map(|_| ()) })
}

pub fn drop_database(client: &Client, db: &str) -> Result<(), String> {
    let database = client.database(db);
    coroutine::run_sync(async move { database.drop().await })
}

pub fn list_collection_names(client: &Client, db: &str) -> Result<Vec<String>, String> {
    let database = client.database(db);
    coroutine::run_sync(async move { database.list_collection_names().await })
}

pub fn find_with_options(
    client: &Client, db: &str, col: &str, filter: Document, opts: mongodb::options::FindOptions,
) -> Result<mongodb::Cursor<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move { collection.find(filter).with_options(opts).await })
}

pub fn find_one_with_options(
    client: &Client, db: &str, col: &str, filter: Document, opts: mongodb::options::FindOneOptions,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move { collection.find_one(filter).with_options(opts).await })
}

pub fn update_one_with_options(
    client: &Client, db: &str, col: &str, filter: Document, update: mongodb::options::UpdateModifications, opts: mongodb::options::UpdateOptions,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.update_one(filter, update).with_options(opts).await })
}

pub fn update_many_with_options(
    client: &Client, db: &str, col: &str, filter: Document, update: mongodb::options::UpdateModifications, opts: mongodb::options::UpdateOptions,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.update_many(filter, update).with_options(opts).await })
}

pub fn replace_one_with_options(
    client: &Client, db: &str, col: &str, filter: Document, replacement: Document, opts: mongodb::options::ReplaceOptions,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.replace_one(filter, replacement).with_options(opts).await })
}

pub fn find_one_and_update_with_options(
    client: &Client, db: &str, col: &str, filter: Document, update: mongodb::options::UpdateModifications, opts: mongodb::options::FindOneAndUpdateOptions,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<RawDocumentBuf>(col);
    coroutine::run_sync(async move { collection.find_one_and_update(filter, update).with_options(opts).await })
}

pub fn find_one_and_replace_with_options(
    client: &Client, db: &str, col: &str, filter: Document, replacement: Document, opts: mongodb::options::FindOneAndReplaceOptions,
) -> Result<Option<RawDocumentBuf>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        match collection.find_one_and_replace(filter, replacement).with_options(opts).await
            .map_err(|e| mongodb::error::Error::custom(e))?
        {
            Some(doc) => {
                let raw = RawDocumentBuf::from_document(&doc)
                    .map_err(|e| mongodb::error::Error::custom(e))?;
                Ok::<_, mongodb::error::Error>(Some(raw))
            }
            None => Ok(None),
        }
    })
}
