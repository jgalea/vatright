import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './e2e',
	testMatch: ['**/*.spec.ts'],
	timeout: process.env.CI ? 150_000 : 60_000,
	expect: { timeout: process.env.CI ? 20_000 : 5_000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	use: {
		baseURL: `http://localhost:${ process.env.VATRIGHT_E2E_PORT || '8899' }`,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
});
