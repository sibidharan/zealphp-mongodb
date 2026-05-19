use bson::Document;
use mongodb::Client;

use crate::coroutine;

pub fn find_one(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<Option<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.find_one(filter).await })
}

pub fn find(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<mongodb::Cursor<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
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
) -> Result<Option<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.find_one_and_update(filter, update).await })
}

pub fn find_one_and_delete(
    client: &Client,
    db: &str,
    col: &str,
    filter: Document,
) -> Result<Option<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
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
) -> Result<Option<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.find_one_and_replace(filter, replacement).await })
}

pub fn list_databases(client: &Client) -> Result<Vec<String>, String> {
    let client = client.clone();
    coroutine::run_sync(async move {
        client.list_database_names().await
    })
}

pub fn insert_many(
    client: &Client, db: &str, col: &str, docs: Vec<Document>,
) -> Result<mongodb::results::InsertManyResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.insert_many(docs).await })
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
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move {
        use futures::TryStreamExt;
        let cursor = collection.list_indexes().await.map_err(|e| e.to_string())?;
        let indexes: Vec<_> = cursor.try_collect().await.map_err(|e: mongodb::error::Error| e.to_string())?;
        Ok::<Vec<Document>, String>(indexes.into_iter().map(|idx| {
            let mut doc = Document::new();
            doc.insert("key", bson::Bson::Document(idx.keys));
            if let Some(opts) = idx.options {
                if let Some(name) = opts.name { doc.insert("name", name); }
                if let Some(unique) = opts.unique { doc.insert("unique", unique); }
                if let Some(sparse) = opts.sparse { doc.insert("sparse", sparse); }
            }
            doc
        }).collect())
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
) -> Result<mongodb::Cursor<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.find(filter).with_options(opts).await })
}

pub fn find_one_with_options(
    client: &Client, db: &str, col: &str, filter: Document, opts: mongodb::options::FindOneOptions,
) -> Result<Option<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.find_one(filter).with_options(opts).await })
}

pub fn update_one_with_options(
    client: &Client, db: &str, col: &str, filter: Document, update: Document, opts: mongodb::options::UpdateOptions,
) -> Result<mongodb::results::UpdateResult, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.update_one(filter, update).with_options(opts).await })
}

pub fn update_many_with_options(
    client: &Client, db: &str, col: &str, filter: Document, update: Document, opts: mongodb::options::UpdateOptions,
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
    client: &Client, db: &str, col: &str, filter: Document, update: Document, opts: mongodb::options::FindOneAndUpdateOptions,
) -> Result<Option<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.find_one_and_update(filter, update).with_options(opts).await })
}

pub fn find_one_and_replace_with_options(
    client: &Client, db: &str, col: &str, filter: Document, replacement: Document, opts: mongodb::options::FindOneAndReplaceOptions,
) -> Result<Option<Document>, String> {
    let collection = client.database(db).collection::<Document>(col);
    coroutine::run_sync(async move { collection.find_one_and_replace(filter, replacement).with_options(opts).await })
}
