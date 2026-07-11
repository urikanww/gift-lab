/**
 * Direct-to-S3 upload for scraped .3mf files (DigitalOcean Spaces, S3-compatible).
 *
 * The backend serves model files from its own private `spaces_models` disk
 * (config/filesystems.php), rooted at `${DO_STORAGE_FOLDER}/models3d`. To keep
 * refs identical to what the backend resolves, we upload each file to the key
 * `${DO_STORAGE_FOLDER}/models3d/{ref}` where {ref} is the models3d/... path the
 * CSV carries (e.g. models3d/makerworld/3018896.3mf).
 *
 * Credentials come from the scraper's own .env (gitignored) - NEVER commit them:
 *   AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION,
 *   AWS_BUCKET, AWS_ENDPOINT, DO_STORAGE_FOLDER (default GIFT_LAB)
 *
 * When creds are absent this module reports "not configured" and the caller
 * keeps the local file only - so a dev run without S3 still works.
 */

let clientPromise = null;

function config() {
  return {
    key: process.env.AWS_ACCESS_KEY_ID || '',
    secret: process.env.AWS_SECRET_ACCESS_KEY || '',
    region: process.env.AWS_DEFAULT_REGION || 'sgp1',
    bucket: process.env.AWS_BUCKET || '',
    endpoint: process.env.AWS_ENDPOINT || '',
    folder: (process.env.DO_STORAGE_FOLDER || 'GIFT_LAB').replace(/\/+$/, ''),
  };
}

export function isS3Configured() {
  const c = config();
  return c.key !== '' && c.secret !== '' && c.bucket !== '';
}

/**
 * Lazily construct the S3 client. @aws-sdk/client-s3 is an optional dependency:
 * if it isn't installed we degrade to local-only rather than crashing the run.
 * @returns {Promise<{client: any, PutObjectCommand: any}|null>}
 */
async function getClient() {
  if (clientPromise) return clientPromise;
  clientPromise = (async () => {
    let mod;
    try {
      mod = await import('@aws-sdk/client-s3');
    } catch {
      console.error('! @aws-sdk/client-s3 not installed - skipping S3 upload (local file kept).');
      return null;
    }
    const c = config();
    const client = new mod.S3Client({
      region: c.region,
      endpoint: c.endpoint || undefined,
      credentials: { accessKeyId: c.key, secretAccessKey: c.secret },
      forcePathStyle: false,
    });
    return { client, PutObjectCommand: mod.PutObjectCommand };
  })();
  return clientPromise;
}

/**
 * Upload one model file. Idempotent-friendly: overwriting the same key is fine.
 * @param {string} ref   models3d/... relative ref the CSV carries
 * @param {Buffer|Uint8Array} bytes
 * @returns {Promise<{uploaded: boolean, key: string|null}>}
 */
export async function uploadModelFile(ref, bytes) {
  if (!isS3Configured()) {
    return { uploaded: false, key: null };
  }
  const c = config();
  const cli = await getClient();
  if (!cli) return { uploaded: false, key: null };

  const key = `${c.folder}/${ref.replace(/^\/+/, '')}`;
  await cli.client.send(
    new cli.PutObjectCommand({
      Bucket: c.bucket,
      Key: key,
      Body: bytes,
      ContentType: 'model/3mf',
      ACL: 'private', // model files are private; served via the backend only
    }),
  );
  console.error(`  uploaded s3://${c.bucket}/${key} (${(bytes.length / 1024 / 1024).toFixed(1)} MB)`);
  return { uploaded: true, key };
}
