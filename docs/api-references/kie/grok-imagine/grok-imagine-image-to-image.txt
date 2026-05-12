> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# grok-imagine/image-to-image

> Content generation using grok-imagine/image-to-image

## File Upload Requirements

Before using the Image-to-Image API, you need to upload your reference image:

<Steps>
  <Step title="Upload Reference Image">
    Use the File Upload API to upload your reference image.

    <Card title="File Upload API" icon="upload" href="/file-upload-api/quickstart">
      Learn how to upload images and get file URLs
    </Card>

    **Requirements:**

    * **File Type**: JPEG, PNG, or WebP format
    * **Max File Size**: 10MB per file
    * **Content**: Image you want to use as reference for generation
  </Step>

  <Step title="Get File URL">
    After upload, you'll receive a file URL that you can use in the `image_urls` parameter.
  </Step>
</Steps>

<Warning>
  * Supported formats: JPEG, PNG, WebP (Max: 10MB)
  * Maximum one image per request
</Warning>

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

````yaml market/grok-imagine/image-to-image.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Grok-imagine API
  description: kie.ai Grok-imagine API Documentation - undefined
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
      summary: Generate content using grok-imagine/image-to-image
      operationId: grok-imagine-image-to-image
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
                    - grok-imagine/image-to-image
                  default: grok-imagine/image-to-image
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `grok-imagine/image-to-image` for this endpoint
                  example: grok-imagine/image-to-image
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
                        A text description specifying the desired content or
                        style of the generated image. (Max length: 390000
                        characters)
                      type: string
                      maxLength: 390000
                      example: >-
                        Recreate the Titanic movie poster with two adorable
                        anthropomorphic cats in the same romantic pose at the
                        bow of the ship. The male cat is an orange tabby wearing
                        a vest, standing behind a white long-haired female cat
                        in a lace dress, holding her paws as they stretch
                        forward in the wind. Both cats are photorealistic with
                        detailed fur, wind-swept hair, and dramatic sunset
                        lighting (warm golden highlights, cool blue shadows).
                        Background: the Titanic ship at dusk with four
                        smokestacks, glowing deck lights, calm ocean, and
                        orange-pink sunset sky. Center title: “CATANIC” in the
                        same gold metallic serif style as Titanic, same size and
                        position.
                    image_urls:
                      description: >-
                        An array containing a single URL string pointing to the
                        reference image. (File URL after upload, not file
                        content; Accepted types: image/jpeg, image/png,
                        image/webp; Max size: 10.0MB)
                      type: array
                      items:
                        type: string
                        format: uri
                      maxItems: 1
                      example:
                        - >-
                          https://static.aiquickdraw.com/tools/example/1767602105243_0MmMCrwq.png
                  required:
                    - image_urls
            example:
              model: grok-imagine/image-to-image
              callBackUrl: https://your-domain.com/api/callback
              input:
                prompt: >-
                  Recreate the Titanic movie poster with two adorable
                  anthropomorphic cats in the same romantic pose at the bow of
                  the ship. The male cat is an orange tabby wearing a vest,
                  standing behind a white long-haired female cat in a lace
                  dress, holding her paws as they stretch forward in the wind.
                  Both cats are photorealistic with detailed fur, wind-swept
                  hair, and dramatic sunset lighting (warm golden highlights,
                  cool blue shadows). Background: the Titanic ship at dusk with
                  four smokestacks, glowing deck lights, calm ocean, and
                  orange-pink sunset sky. Center title: “CATANIC” in the same
                  gold metallic serif style as Titanic, same size and position.
                image_urls:
                  - >-
                    https://static.aiquickdraw.com/tools/example/1767602105243_0MmMCrwq.png
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
                            example: task_grok-imagine_1767694553297
              example:
                code: 200
                msg: success
                data:
                  taskId: task_grok-imagine_1767694553297
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