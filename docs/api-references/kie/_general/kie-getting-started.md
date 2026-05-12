> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Getting Started with KIE API (Important)

> Welcome to **KIE**. This guide walks you through the essential information you need to start integrating KIE APIs into your product, including models, pricing, authentication, request flow, limits, and support.

We aim to be transparent, practical, and developer-friendly. Please read this carefully before going to production.

## 1. Available Models & Playground

You can find the **latest supported models** on our Market page:

👉 [https://kie.ai/market](https://kie.ai/market)

* We continuously update and onboard new models as soon as they are stable.
* Each model page links to its **Playground**, where you can test and experiment directly in our UI before calling the API.
* The Playground is the best place to understand model behavior, parameters, and output formats.

***

## 2. Pricing

The complete and up-to-date pricing list is available here:

👉 [https://kie.ai/pricing](https://kie.ai/pricing)

* Our prices are typically **30%–50% lower than official APIs**.
* For some models, discounts can reach **up to 80%**.
* Pricing may change as upstream providers adjust their costs, so always refer to the pricing page for the latest numbers.

***

## 3. Creating and Securing Your API Key

Create and manage your API keys here:

👉 [https://kie.ai/api-key](https://kie.ai/api-key)

**Important security notes:**

* **Never expose your API key in frontend code** (browser, mobile apps, public repositories).
* Treat your API key as a secret.

To help protect your usage, we provide:

* **Rate limits per key** (hourly, daily, and total usage caps)
* **IP whitelist** support, allowing only approved server IPs to access the API

These features help prevent accidental overuse and unauthorized requests.

***

## 4. Required Request Headers

Every API request **must** include the correct headers:

```http  theme={null}
Authorization: Bearer <YOUR_API_KEY>
Content-Type: application/json
```

If these headers are missing or incorrect, you may receive:

```json  theme={null}
{"code":401,"msg":"You do not have access permissions"}
```

Always double-check your headers when debugging authentication issues.

***

## 5. Logs & Task Details

You can inspect all your historical tasks here:

👉 [https://kie.ai/logs](https://kie.ai/logs)

For each task, you can view:

* Creation time
* Model used
* Input parameters
* Task status
* Credit consumption
* Final results or error details

If you ever suspect incorrect credit usage, this page is the source of truth for verification.

***

## 6. Data Retention Policy

Please note our retention rules:

* **Generated media files**: stored for 14 days, then automatically deleted
* **Log records** (text / metadata): stored for 2 months, then automatically deleted

If you need long-term access, make sure to download and store results on your side in time.

***

## 7. Asynchronous Task Model

All generation tasks on KIE are asynchronous.

A successful request returns:

* **HTTP 200**
* A `task_id`

<Warning>
  A 200 response only means the task was created, not completed.
</Warning>

To get the final result, you must either:

* Provide a callback (webhook) URL in the request, or
* Actively poll the "query record info" API using the `task_id`

***

## 8. Rate Limits & Concurrency

By default, we apply the following limits:

* Up to 20 new generation requests per 10 seconds
* This typically allows 100+ concurrent running tasks
* Limits are applied per account

If you exceed the limit:

* Requests will be rejected with HTTP 429
* Rejected requests will not enter the queue

For most users, this is more than sufficient.
If you consistently hit 429 errors, you may contact support to request a higher limit — approvals are handled cautiously.

***

## 9. Developer Support

The recommended support channels are available directly from the dashboard (bottom-left menu):

* Get help on Discord
* Get help on Telegram

What you get:

* Private, 1-on-1 channels
* Your data and conversations remain confidential
* Faster and more technical responses

**Support hours:**
UTC 21:00 – UTC 17:00 (next day)

You may also email us at [support@kie.ai](mailto:support@kie.ai), but this is not the preferred or fastest option.

***

## 10. Stability Expectations

We provide access to top-tier, highly competitive APIs at very aggressive pricing.

That said:

* We are not perfect
* Our overall stability may be slightly lower than official providers
* This is a conscious trade-off

In practice, KIE is stable enough to support production workloads and long-term business growth, but we believe in setting realistic expectations upfront.

***

## 11. About the Team

KIE is built by a small startup team.

* We move fast
* We care deeply about developer experience
* We are constantly improving

At the same time, we acknowledge that:

* Not everything is perfect
* We can't satisfy every use case immediately

Your feedback helps us improve — and we genuinely appreciate it.
