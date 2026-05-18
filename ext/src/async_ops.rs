use mongodb::Client;
use bson::Document;

pub async fn exec_async(
    client: Client,
    db: String,
    col: String,
    op: String,
    filter_or_doc: Option<Document>,
    update_or_pipeline: Option<Vec<Document>>,
) -> String {
    let collection = client.database(&db).collection::<Document>(&col);

    match op.as_str() {
        "find" => {
            let filter = filter_or_doc.unwrap_or_default();
            let opts_doc = update_or_pipeline.and_then(|v| v.into_iter().next());
            let mut find_opts = mongodb::options::FindOptions::default();
            if let Some(ref opts) = opts_doc {
                if let Ok(limit) = opts.get_i64("limit") {
                    find_opts.limit = Some(limit);
                } else if let Ok(limit) = opts.get_i32("limit") {
                    find_opts.limit = Some(limit as i64);
                }
                if let Ok(skip) = opts.get_i64("skip") {
                    find_opts.skip = Some(skip as u64);
                } else if let Ok(skip) = opts.get_i32("skip") {
                    find_opts.skip = Some(skip as u64);
                }
                if let Ok(sort_doc) = opts.get_document("sort") {
                    find_opts.sort = Some(sort_doc.clone());
                }
                if let Ok(proj_doc) = opts.get_document("projection") {
                    find_opts.projection = Some(proj_doc.clone());
                }
            }
            match collection.find(filter).with_options(find_opts).await {
                Ok(mut cursor) => {
                    use futures::stream::StreamExt;
                    let mut docs = Vec::new();
                    while let Some(Ok(doc)) = cursor.next().await {
                        docs.push(doc_to_json(&doc));
                    }
                    format!("[{}]", docs.join(","))
                }
                Err(e) => error_json(&e.to_string()),
            }
        }
        "find_one" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.find_one(filter).await {
                Ok(Some(doc)) => doc_to_json(&doc),
                Ok(None) => "null".to_string(),
                Err(e) => error_json(&e.to_string()),
            }
        }
        "insert_one" => {
            let doc = filter_or_doc.unwrap_or_default();
            match collection.insert_one(doc).await {
                Ok(r) => {
                    let id_str = bson_id_to_string(&r.inserted_id);
                    format!(
                        "{{\"inserted_id\":\"{}\",\"acknowledged\":true,\"inserted_count\":1}}",
                        id_str
                    )
                }
                Err(e) => error_json(&e.to_string()),
            }
        }
        "update_one" => {
            let filter = filter_or_doc.unwrap_or_default();
            let update = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            match collection.update_one(filter, update).await {
                Ok(r) => format!(
                    "{{\"matched_count\":{},\"modified_count\":{},\"acknowledged\":true}}",
                    r.matched_count, r.modified_count
                ),
                Err(e) => error_json(&e.to_string()),
            }
        }
        "update_many" => {
            let filter = filter_or_doc.unwrap_or_default();
            let update = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            match collection.update_many(filter, update).await {
                Ok(r) => format!(
                    "{{\"matched_count\":{},\"modified_count\":{},\"acknowledged\":true}}",
                    r.matched_count, r.modified_count
                ),
                Err(e) => error_json(&e.to_string()),
            }
        }
        "delete_one" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.delete_one(filter).await {
                Ok(r) => format!("{{\"deleted_count\":{},\"acknowledged\":true}}", r.deleted_count),
                Err(e) => error_json(&e.to_string()),
            }
        }
        "delete_many" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.delete_many(filter).await {
                Ok(r) => format!("{{\"deleted_count\":{},\"acknowledged\":true}}", r.deleted_count),
                Err(e) => error_json(&e.to_string()),
            }
        }
        "replace_one" => {
            let filter = filter_or_doc.unwrap_or_default();
            let replacement = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            match collection.replace_one(filter, replacement).await {
                Ok(r) => format!(
                    "{{\"matched_count\":{},\"modified_count\":{},\"acknowledged\":true}}",
                    r.matched_count, r.modified_count
                ),
                Err(e) => error_json(&e.to_string()),
            }
        }
        "count_documents" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.count_documents(filter).await {
                Ok(n) => format!("{{\"count\":{}}}", n),
                Err(e) => error_json(&e.to_string()),
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
                Ok(values) => {
                    let json_vals: Vec<String> = values.iter().map(|v| {
                        serde_json::to_string(v).unwrap_or_else(|_| "null".to_string())
                    }).collect();
                    format!("[{}]", json_vals.join(","))
                }
                Err(e) => error_json(&e.to_string()),
            }
        }
        "aggregate" => {
            let pipeline = update_or_pipeline.unwrap_or_default();
            match collection.aggregate(pipeline).await {
                Ok(mut cursor) => {
                    use futures::stream::StreamExt;
                    let mut docs = Vec::new();
                    while let Some(Ok(doc)) = cursor.next().await {
                        docs.push(doc_to_json(&doc));
                    }
                    format!("[{}]", docs.join(","))
                }
                Err(e) => error_json(&e.to_string()),
            }
        }
        "find_one_and_update" => {
            let filter = filter_or_doc.unwrap_or_default();
            let update = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            match collection.find_one_and_update(filter, update).await {
                Ok(Some(doc)) => doc_to_json(&doc),
                Ok(None) => "null".to_string(),
                Err(e) => error_json(&e.to_string()),
            }
        }
        "find_one_and_delete" => {
            let filter = filter_or_doc.unwrap_or_default();
            match collection.find_one_and_delete(filter).await {
                Ok(Some(doc)) => doc_to_json(&doc),
                Ok(None) => "null".to_string(),
                Err(e) => error_json(&e.to_string()),
            }
        }
        "find_one_and_replace" => {
            let filter = filter_or_doc.unwrap_or_default();
            let replacement = update_or_pipeline.and_then(|v| v.into_iter().next()).unwrap_or_default();
            match collection.find_one_and_replace(filter, replacement).await {
                Ok(Some(doc)) => doc_to_json(&doc),
                Ok(None) => "null".to_string(),
                Err(e) => error_json(&e.to_string()),
            }
        }
        _ => error_json(&format!("Unknown operation: {}", op)),
    }
}

fn bson_id_to_string(bson: &bson::Bson) -> String {
    match bson {
        bson::Bson::ObjectId(oid) => oid.to_hex(),
        bson::Bson::String(s) => s.clone(),
        bson::Bson::Int32(n) => n.to_string(),
        bson::Bson::Int64(n) => n.to_string(),
        other => serde_json::to_string(other).unwrap_or_else(|_| other.to_string()),
    }
}

fn error_json(msg: &str) -> String {
    let escaped = msg.replace('\\', "\\\\").replace('"', "\\\"");
    format!("{{\"__error\":\"{}\"}}", escaped)
}

fn doc_to_json(doc: &Document) -> String {
    serde_json::to_string(doc).unwrap_or_else(|_| "{}".to_string())
}
