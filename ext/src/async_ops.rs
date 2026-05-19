use bson::{doc, Bson, Document};
use futures::StreamExt;
use mongodb::Client;

use crate::async_store::AsyncResult;
use crate::cursor;

pub async fn exec_async(
    client: Client,
    db: String,
    col: String,
    op: String,
    filter_or_doc: Option<Document>,
    update_or_pipeline: Option<Vec<Document>>,
) -> AsyncResult {
    let collection = client.database(&db).collection::<Document>(&col);
    let (filter_or_doc, opts_doc) = extract_options(filter_or_doc);

    match op.as_str() {
        "find" => {
            let filter = filter_or_doc.unwrap_or_default();
            let extra_opts = update_or_pipeline.and_then(|v| v.into_iter().next());
            let combined_opts = extra_opts.or(opts_doc);
            let find_opts = build_find_options(combined_opts.as_ref());
            match collection.find(filter).with_options(find_opts).await {
                Ok(mut cursor) => {
                    let mut docs = Vec::new();
                    while let Some(Ok(doc)) = cursor.next().await {
                        docs.push(doc);
                    }
                    AsyncResult::Docs(docs)
                }
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "find_one" => {
            let filter = filter_or_doc.unwrap_or_default();
            let mut fo = mongodb::options::FindOneOptions::default();
            if let Some(ref opts) = opts_doc {
                if let Ok(proj_doc) = opts.get_document("projection") {
                    fo.projection = Some(proj_doc.clone());
                }
                if let Ok(sort_doc) = opts.get_document("sort") {
                    fo.sort = Some(sort_doc.clone());
                }
            }
            match collection.find_one(filter).with_options(fo).await {
                Ok(doc) => AsyncResult::Doc(doc),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "insert_one" => {
            let document = filter_or_doc.unwrap_or_default();
            match collection.insert_one(document).await {
                Ok(r) => AsyncResult::Scalar(doc! {
                    "inserted_id": r.inserted_id,
                    "acknowledged": true,
                    "inserted_count": 1_i64,
                }),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "update_one" => {
            let filter = filter_or_doc.unwrap_or_default();
            let update = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            let mut uo = mongodb::options::UpdateOptions::default();
            if let Some(ref opts) = opts_doc {
                if let Ok(upsert) = opts.get_bool("upsert") {
                    uo.upsert = Some(upsert);
                }
            }
            match collection.update_one(filter, update).with_options(uo).await {
                Ok(r) => AsyncResult::Scalar(update_result_doc(&r)),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "update_many" => {
            let filter = filter_or_doc.unwrap_or_default();
            let update = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            let mut uo = mongodb::options::UpdateOptions::default();
            if let Some(ref opts) = opts_doc {
                if let Ok(upsert) = opts.get_bool("upsert") {
                    uo.upsert = Some(upsert);
                }
            }
            match collection.update_many(filter, update).with_options(uo).await {
                Ok(r) => AsyncResult::Scalar(update_result_doc(&r)),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "delete_one" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.delete_one(filter).await {
                Ok(r) => AsyncResult::Scalar(doc! {
                    "deleted_count": r.deleted_count as i64,
                    "acknowledged": true,
                }),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "delete_many" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.delete_many(filter).await {
                Ok(r) => AsyncResult::Scalar(doc! {
                    "deleted_count": r.deleted_count as i64,
                    "acknowledged": true,
                }),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "replace_one" => {
            let filter = filter_or_doc.unwrap_or_default();
            let replacement = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            let mut ro = mongodb::options::ReplaceOptions::default();
            if let Some(ref opts) = opts_doc {
                if let Ok(upsert) = opts.get_bool("upsert") {
                    ro.upsert = Some(upsert);
                }
            }
            match collection.replace_one(filter, replacement).with_options(ro).await {
                Ok(r) => AsyncResult::Scalar(update_result_doc(&r)),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "count_documents" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.count_documents(filter).await {
                Ok(n) => AsyncResult::Scalar(doc! { "count": n as i64 }),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "distinct" => {
            let field_name = filter_or_doc
                .as_ref()
                .and_then(|d| d.get_str("__field").ok())
                .unwrap_or("")
                .to_string();
            let filter = filter_or_doc
                .map(|mut d| { d.remove("__field"); d })
                .unwrap_or_default();
            match collection.distinct(&field_name, filter).await {
                Ok(values) => AsyncResult::Values(values),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "aggregate" => {
            let pipeline = update_or_pipeline.unwrap_or_default();
            match collection.aggregate(pipeline).await {
                Ok(mut cursor) => {
                    let mut docs = Vec::new();
                    while let Some(Ok(doc)) = cursor.next().await {
                        docs.push(doc);
                    }
                    AsyncResult::Docs(docs)
                }
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "find_one_and_update" => {
            let filter = filter_or_doc.unwrap_or_default();
            let update = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            let fo = build_find_and_modify_options(&opts_doc);
            match collection.find_one_and_update(filter, update).with_options(fo).await {
                Ok(doc) => AsyncResult::Doc(doc),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "find_one_and_delete" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.find_one_and_delete(filter).await {
                Ok(doc) => AsyncResult::Doc(doc),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "find_one_and_replace" => {
            let filter = filter_or_doc.unwrap_or_default();
            let replacement = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            let mut fo = mongodb::options::FindOneAndReplaceOptions::default();
            if let Some(ref opts) = opts_doc {
                if let Ok(rd) = opts.get_i32("returnDocument") {
                    if rd == 2 { fo.return_document = Some(mongodb::options::ReturnDocument::After); }
                } else if let Ok(rd) = opts.get_i64("returnDocument") {
                    if rd == 2 { fo.return_document = Some(mongodb::options::ReturnDocument::After); }
                }
                if let Ok(upsert) = opts.get_bool("upsert") {
                    fo.upsert = Some(upsert);
                }
            }
            match collection.find_one_and_replace(filter, replacement).with_options(fo).await {
                Ok(doc) => AsyncResult::Doc(doc),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "insert_many" => {
            let docs_bson = filter_or_doc.map(|d| {
                d.get_array("__docs").ok().map(|arr| {
                    arr.iter().filter_map(|v| {
                        if let Bson::Document(doc) = v { Some(doc.clone()) } else { None }
                    }).collect::<Vec<_>>()
                }).unwrap_or_default()
            }).unwrap_or_default();
            match collection.insert_many(docs_bson).await {
                Ok(r) => {
                    let ids: Vec<Bson> = r.inserted_ids.into_values().collect();
                    let mut result = doc! {
                        "acknowledged": true,
                        "inserted_count": ids.len() as i64,
                    };
                    result.insert("inserted_ids", Bson::Array(ids));
                    AsyncResult::Scalar(result)
                }
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "estimated_document_count" => {
            match collection.estimated_document_count().await {
                Ok(n) => AsyncResult::Scalar(doc! { "count": n as i64 }),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "run_command" => {
            let cmd = filter_or_doc.unwrap_or_default();
            let database = client.database(&db);
            match database.run_command(cmd).await {
                Ok(doc) => AsyncResult::Doc(Some(doc)),
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "find_cursor" => {
            let filter = filter_or_doc.unwrap_or_default();
            let extra_opts = update_or_pipeline.and_then(|v| v.into_iter().next());
            let combined_opts = extra_opts.or(opts_doc);
            let find_opts = build_find_options(combined_opts.as_ref());
            match collection.find(filter).with_options(find_opts).await {
                Ok(mongo_cursor) => eager_batch_cursor(mongo_cursor).await,
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        "aggregate_cursor" => {
            let pipeline = update_or_pipeline.unwrap_or_default();
            match collection.aggregate(pipeline).await {
                Ok(mongo_cursor) => eager_batch_cursor(mongo_cursor).await,
                Err(e) => AsyncResult::Error(e.to_string()),
            }
        }
        _ => AsyncResult::Error(format!("Unknown operation: {}", op)),
    }
}

fn extract_options(filter_or_doc: Option<Document>) -> (Option<Document>, Option<Document>) {
    match filter_or_doc {
        Some(mut doc) => {
            let opts = doc.remove("__options").and_then(|v| {
                if let Bson::Document(d) = v { Some(d) } else { None }
            });
            (Some(doc), opts)
        }
        None => (None, None),
    }
}

fn build_find_options(opts: Option<&Document>) -> mongodb::options::FindOptions {
    let mut fo = mongodb::options::FindOptions::default();
    if let Some(opts) = opts {
        if let Ok(limit) = opts.get_i64("limit") {
            fo.limit = Some(limit);
        } else if let Ok(limit) = opts.get_i32("limit") {
            fo.limit = Some(limit as i64);
        }
        if let Ok(skip) = opts.get_i64("skip") {
            fo.skip = Some(skip as u64);
        } else if let Ok(skip) = opts.get_i32("skip") {
            fo.skip = Some(skip as u64);
        }
        if let Ok(sort_doc) = opts.get_document("sort") {
            fo.sort = Some(sort_doc.clone());
        }
        if let Ok(proj_doc) = opts.get_document("projection") {
            fo.projection = Some(proj_doc.clone());
        }
    }
    fo
}

fn build_find_and_modify_options(opts_doc: &Option<Document>) -> mongodb::options::FindOneAndUpdateOptions {
    let mut fo = mongodb::options::FindOneAndUpdateOptions::default();
    if let Some(ref opts) = opts_doc {
        if let Ok(rd) = opts.get_i32("returnDocument") {
            if rd == 2 { fo.return_document = Some(mongodb::options::ReturnDocument::After); }
        } else if let Ok(rd) = opts.get_i64("returnDocument") {
            if rd == 2 { fo.return_document = Some(mongodb::options::ReturnDocument::After); }
        }
        if let Ok(proj_doc) = opts.get_document("projection") {
            fo.projection = Some(proj_doc.clone());
        }
        if let Ok(upsert) = opts.get_bool("upsert") {
            fo.upsert = Some(upsert);
        }
    }
    fo
}

fn update_result_doc(r: &mongodb::results::UpdateResult) -> Document {
    let mut d = doc! {
        "matched_count": r.matched_count as i64,
        "modified_count": r.modified_count as i64,
        "acknowledged": true,
    };
    if let Some(ref id) = r.upserted_id {
        d.insert("upserted_id", id.clone());
    }
    d
}

const EAGER_BATCH_SIZE: usize = 100;

async fn eager_batch_cursor(mut mongo_cursor: mongodb::Cursor<Document>) -> AsyncResult {
    let mut docs = Vec::with_capacity(EAGER_BATCH_SIZE);
    let mut exhausted = false;

    for _ in 0..EAGER_BATCH_SIZE {
        match mongo_cursor.next().await {
            Some(Ok(doc)) => docs.push(doc),
            Some(Err(e)) => return AsyncResult::Error(e.to_string()),
            None => { exhausted = true; break; }
        }
    }

    if exhausted {
        let mut result = doc! { "exhausted": true };
        result.insert("docs", Bson::Array(docs.into_iter().map(Bson::Document).collect()));
        AsyncResult::Scalar(result)
    } else {
        let cursor_id = cursor::store_cursor(mongo_cursor);
        let mut result = doc! { "cursor_id": cursor_id as i64, "exhausted": false };
        result.insert("docs", Bson::Array(docs.into_iter().map(Bson::Document).collect()));
        AsyncResult::Scalar(result)
    }
}
