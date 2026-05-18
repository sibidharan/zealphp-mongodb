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
        collection.create_index(index_model).await
            .map(|r| r.index_name)
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
