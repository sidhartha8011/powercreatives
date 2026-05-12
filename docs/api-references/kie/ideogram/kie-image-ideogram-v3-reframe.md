> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Ideogram - v3-reframe

> Image generation by ideogram/v3-reframe

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

````yaml market/ideogram/v3-reframe.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Ideogram API
  description: kie.ai Ideogram API Documentation
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
      summary: Generate content using ideogram/v3-reframe
      operationId: ideogram-v3-reframe
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
                    - ideogram/v3-reframe
                  default: ideogram/v3-reframe
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `ideogram/v3-reframe` for this endpoint
                  example: ideogram/v3-reframe
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
                    image_url:
                      description: >-
                        The image URL to reframe (File URL after upload, not
                        file content; Accepted types: image/jpeg, image/png,
                        image/webp; Max size: 10.0MB)
                      type: string
                      example: >-
                        https://file.aiquickdraw.com/custom-page/akr/section-images/1757168087001amxesd6e.webp
                    image_size:
                      description: The resolution for the reframed output image
                      type: string
                      enum:
                        - square
                        - square_hd
                        - portrait_4_3
                        - portrait_16_9
                        - landscape_4_3
                        - landscape_16_9
                      default: square_hd
                      example: square_hd
                    rendering_speed:
                      description: The rendering speed to use
                      type: string
                      enum:
                        - TURBO
                        - BALANCED
                        - QUALITY
                      default: BALANCED
                      example: BALANCED
                    style:
                      description: >-
                        The style type to generate with. Cannot be used with
                        style_codes
                      type: string
                      enum:
                        - AUTO
                        - GENERAL
                        - REALISTIC
                        - DESIGN
                      default: AUTO
                      example: AUTO
                    num_images:
                      description: Select description
                      type: string
                      enum:
                        - '1'
                        - '2'
                        - '3'
                        - '4'
                      default: '1'
                      example: '1'
                    seed:
                      description: Seed for the random number generator
                      type: number
                      example: 0
                  required:
                    - image_url
                    - image_size
            example:
              model: ideogram/v3-reframe
              callBackUrl: https://your-domain.com/api/callback
              input:
                image_url: >-
                  https://file.aiquickdraw.com/custom-page/akr/section-images/1757168087001amxesd6e.webp
                image_size: square_hd
                rendering_speed: BALANCED
                style: AUTO
                num_images: '1'
                seed: 0
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
                            example: task_ideogram_1765177570132
              example:
                code: 200
                msg: success
                data:
                  taskId: task_ideogram_1765177570132
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