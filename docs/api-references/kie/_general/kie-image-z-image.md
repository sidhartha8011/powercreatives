> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# z-image

> Image generation by z-image

## Query Task Status

After submitting a task, use the unified query endpoint to check progress and retrieve results:

<Card title="Get Task Details" icon="magnifying-glass" href="/market/common/get-task-detail">
  Learn how to query task status and retrieve generation results
</Card>

<Tip>
  For production use, we recommend using the `callBackUrl` parameter to receive automatic notifications when generation completes, rather than polling the status endpoint.
</Tip>

## Related Resources

<CardGroup cols={2}>
  <Card title="Market Overview" icon="store" href="/market/quickstart">
    Explore all available models
  </Card>

  <Card title="Common API" icon="gear" href="/common-api/get-account-credits">
    Check credits and account usage
  </Card>
</CardGroup>


## OpenAPI

````yaml market/z-image/z-image.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Z-image API
  description: kie.ai Z-image API Documentation
  version: 1.0.0
  contact:
    name: Technical Support
    email: support@kie.ai
servers:
  - url: https://api.kie.ai
    description: API Server
security:
  - BearerAuth: []
paths:
  /api/v1/jobs/createTask:
    post:
      summary: Generate content using z-image
      operationId: z-image
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required:
                - model
              properties:
                model:
                  type: string
                  enum:
                    - z-image
                  default: z-image
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `z-image` for this endpoint
                  example: z-image
                callBackUrl:
                  type: string
                  format: uri
                  description: >-
                    The URL to receive generation task completion updates.
                    Optional but recommended for production use.


                    - System will POST task status and results to this URL when
                    generation completes

                    - Callback includes generated content URLs and task
                    information

                    - Your callback endpoint should accept POST requests with
                    JSON payload containing results

                    - Alternatively, use the Get Task Details endpoint to poll
                    task status

                    - To ensure callback security, see [Webhook Verification
                    Guide](/common-api/webhook-verification) for signature
                    verification implementation
                  example: https://your-domain.com/api/callback
                input:
                  type: object
                  description: Input parameters for the generation task
                  properties:
                    prompt:
                      description: >-
                        A text description of the image you want to generate
                        (Max length: 1000 characters)
                      type: string
                      maxLength: 1000
                      example: >-
                        Generate a photorealistic image of a cafe terrace in the
                        Marais district of Paris on a Wednesday morning in March
                        2025. It is a crisp, cool spring morning with clear
                        skies. Locals are drinking coffee. In sharp focus should
                        be a young woman with a pixie cut wearing a scarf,
                        stirring a cappuccino and looking thoughtfully to the
                        side; the waiter and street traffic behind her are
                        blurred. The photo should have the candid, natural
                        morning light feel of an iPhone image.
                    aspect_ratio:
                      description: >-
                        Aspect ratio for the generated image. Select 'auto' to
                        match the first input image ratio (requires input
                        image).
                      type: string
                      enum:
                        - '1:1'
                        - '4:3'
                        - '3:4'
                        - '16:9'
                        - '9:16'
                      default: '1:1'
                      example: '1:1'
                  required:
                    - prompt
                    - aspect_ratio
            example:
              model: z-image
              callBackUrl: https://your-domain.com/api/callback
              input:
                prompt: >-
                  Generate a photorealistic image of a cafe terrace in the
                  Marais district of Paris on a Wednesday morning in March 2025.
                  It is a crisp, cool spring morning with clear skies. Locals
                  are drinking coffee. In sharp focus should be a young woman
                  with a pixie cut wearing a scarf, stirring a cappuccino and
                  looking thoughtfully to the side; the waiter and street
                  traffic behind her are blurred. The photo should have the
                  candid, natural morning light feel of an iPhone image.
                aspect_ratio: '1:1'
      responses:
        '200':
          description: Request successful
          content:
            application/json:
              schema:
                allOf:
                  - $ref: '#/components/schemas/ApiResponse'
                  - type: object
                    properties:
                      data:
                        type: object
                        properties:
                          taskId:
                            type: string
                            description: >-
                              Task ID, can be used with Get Task Details
                              endpoint to query task status
                            example: task_z-image_1765174270120
              example:
                code: 200
                msg: success
                data:
                  taskId: task_z-image_1765174270120
        '500':
          $ref: '#/components/responses/Error'
components:
  schemas:
    ApiResponse:
      type: object
      properties:
        code:
          type: integer
          enum:
            - 200
            - 401
            - 402
            - 404
            - 422
            - 429
            - 455
            - 500
            - 501
            - 505
          description: >-
            Response status code


            - **200**: Success - Request has been processed successfully

            - **401**: Unauthorized - Authentication credentials are missing or
            invalid

            - **402**: Insufficient Credits - Account does not have enough
            credits to perform the operation

            - **404**: Not Found - The requested resource or endpoint does not
            exist

            - **422**: Validation Error - The request parameters failed
            validation checks

            - **429**: Rate Limited - Request limit has been exceeded for this
            resource

            - **455**: Service Unavailable - System is currently undergoing
            maintenance

            - **500**: Server Error - An unexpected error occurred while
            processing the request

            - **501**: Generation Failed - Content generation task failed

            - **505**: Feature Disabled - The requested feature is currently
            disabled
        msg:
          type: string
          description: Response message, error description when failed
          example: success
  responses:
    Error:
      description: Server Error
  securitySchemes:
    BearerAuth:
      type: http
      scheme: bearer
      bearerFormat: API Key
      description: >-
        All APIs require authentication via Bearer Token.


        Get API Key:

        1. Visit [API Key Management Page](https://kie.ai/api-key) to get your
        API Key


        Usage:

        Add to request header:

        Authorization: Bearer YOUR_API_KEY


        Note:

        - Keep your API Key secure and do not share it with others

        - If you suspect your API Key has been compromised, reset it immediately
        in the management page

````