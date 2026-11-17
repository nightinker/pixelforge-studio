import { Container, getContainer } from '@cloudflare/containers';

/**
 * Secrets forwarded from the Worker into the Laravel container as environment
 * variables. Set each with `wrangler secret put <NAME>`; anything not set is left
 * out so Laravel falls back to its own defaults. None of these are in git or in the image.
 */
const FORWARDED_ENV = [
  'APP_KEY',
  'APP_URL',
  'DB_HOST',
  'DB_PORT',
  'DB_DATABASE',
  'DB_USERNAME',
  'DB_PASSWORD',
  'DB_SSLMODE',
  'PIXELFORGE_BASE_URL',
  'PIXELFORGE_API_KEY',
  'PIXELFORGE_WEBHOOK_SECRET',
  'PIXELFORGE_MARKUP_PERCENT',
] as const;

type Env = {
  APP_CONTAINER: DurableObjectNamespace<PixelforgeContainer>;
} & Partial<Record<(typeof FORWARDED_ENV)[number], string>>;

export class PixelforgeContainer extends Container<Env> {
  defaultPort = 8080;
  // Laravel's cheap health route, so readiness does not boot a full page.
  pingEndpoint = 'localhost/up';
  // Stay warm through quiet spells so the queue worker and scheduler inside keep running.
  sleepAfter = '15m';

  constructor(ctx: DurableObjectState<{}>, env: Env) {
    super(ctx, env);
    this.envVars = {
      APP_ENV: 'production',
      APP_DEBUG: 'false',
      ...Object.fromEntries(
        FORWARDED_ENV.flatMap((name) => (env[name] === undefined ? [] : [[name, env[name] as string]])),
      ),
    };
  }

  // The container's own logs are not visible from here, so surface its lifecycle:
  // the exit code alone tells a crash (1), a missing command (127) and an OOM kill (137) apart.
  override onStart(): void {
    console.log('container started');
  }

  override onStop(params: unknown): void {
    console.log('container stopped', JSON.stringify(params));
  }

  override onError(error: unknown): void {
    console.log('container error', error instanceof Error ? error.message : String(error));
    throw error;
  }
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    // A single named instance: one queue worker, one scheduler.
    const container = getContainer(env.APP_CONTAINER, 'app');
    try {
      // fetch() starts a cold container and waits for its port itself.
      return await container.fetch(request);
    } catch (error) {
      return new Response(`App container failed: ${error instanceof Error ? error.message : String(error)}`, {
        status: 503,
        headers: { 'Retry-After': '10' },
      });
    }
  },

  // Cron wake-up: touching /up starts the container if it has slept, which starts
  // its scheduler, which runs generations:reconcile.
  async scheduled(_event: ScheduledController, env: Env): Promise<void> {
    await getContainer(env.APP_CONTAINER, 'app').fetch(new Request('http://localhost/up'));
  },
};
