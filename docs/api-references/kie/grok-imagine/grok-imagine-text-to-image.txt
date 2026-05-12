> ## Documentation Index
> Fetch the complete documentation index at: https://docs.kie.ai/llms.txt
> Use this file to discover all available pages before exploring further.

# Grok Imagine - Text to Image

> High-quality photorealistic image generation powered by Grok's advanced AI model

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

````yaml market/grok-imagine/text-to-image.json post /api/v1/jobs/createTask
openapi: 3.0.0
info:
  title: Grok Imagine API
  description: kie.ai Grok Imagine API Documentation - Text to Image Generation
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
      summary: Generate Grok Imagine Image
      description: >-
        Create a new Grok Imagine text-to-image generation task using the
        grok-imagine/text-to-image model.


        ### Important Notes

        - Generated images are processed asynchronously

        - Use the returned taskId to track generation progress

        - Callback URL is recommended for production use to receive automatic
        notifications when generation completes

        - Generated image URLs are valid for 24 hours - download and store
        images immediately

        - Supports multiple aspect ratios for flexible image composition

        - High-quality photorealistic image generation


        ### Generation Time

        Image generation typically takes:

        - **Standard quality**: 5-10 seconds

        - **High complexity prompts**: 10-15 seconds


        ### Rate Limits

        - Maximum 50 concurrent tasks per account

        - Maximum 500 task creations per hour

        - Single image per request


        ### Best Practices

        **Writing Effective Prompts:**

        - Be specific and detailed about subject, style, lighting, mood, and
        composition

        - Use descriptive language with adjectives and artistic references

        - Mention photography style, art style, or visual aesthetic

        - Include technical details like camera angles, depth of field, lighting
        conditions


        **Choosing Aspect Ratios:**

        - **2:3 (Portrait)**: Best for portraits, vertical social media, phone
        wallpapers

        - **3:2 (Landscape)**: Ideal for landscapes, wide scenes, desktop
        wallpapers

        - **1:1 (Square)**: Perfect for Instagram posts, profile pictures,
        balanced compositions


        ### Common Issues

        **Image quality not as expected:** Make prompts more detailed and
        specific, add style references and lighting descriptions

        **Generation taking too long:** Typical generation is 5-15 seconds;
        check task status or verify callback URL accessibility

        **Results don't match prompt:** Be more explicit, use clear descriptive
        language, avoid contradictory instructions
      operationId: grok-imagine-text-to-image
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                model:
                  type: string
                  enum:
                    - grok-imagine/text-to-image
                  default: grok-imagine/text-to-image
                  description: |-
                    The model name to use for generation. Required field.

                    - Must be `grok-imagine/text-to-image` for this endpoint
                  example: grok-imagine/text-to-image
                callBackUrl:
                  type: string
                  format: uri
                  description: >-
                    The URL to receive image generation task completion updates.
                    Optional but recommended for production use.


                    - System will POST task status and results to this URL when
                    image generation completes

                    - Callback includes generated image URLs and task
                    information

                    - Your callback endpoint should accept POST requests with
                    JSON payload containing image results

                    - Alternatively, use the Get Task Details endpoint to poll
                    task status

                    - To ensure callback security, see [Webhook Verification
                    Guide](/common-api/webhook-verification) for signature
                    verification implementation
                  example: https://your-domain.com/api/callback
                input:
                  type: object
                  description: Input parameters for the image generation task
                  properties:
                    prompt:
                      type: string
                      description: >-
                        Text prompt describing the desired image. Required
                        field.


                        - Should be detailed and specific about the desired
                        visual elements

                        - Describe composition, style, lighting, mood, and other
                        visual details

                        - Maximum length: 5000 characters

                        - Supports English language prompts
                      example: >-
                        Cinematic portrait of a woman sitting by a vinyl record
                        player, retro living room background, soft ambient
                        lighting, warm earthy tones, nostalgic 1970s wardrobe,
                        reflective mood, gentle film grain texture, shallow
                        depth of field, vintage editorial photography style.
                    aspect_ratio:
                      type: string
                      description: >-
                        Specifies the width-to-height ratio of the generated
                        image. Controls the aspect ratio of the output.


                        - **2:3**: Portrait orientation (vertical)

                        - **3:2**: Landscape orientation (horizontal) 

                        - **1:1**: Square format

                        - **16:9**: Wide screen format

                        - **9:16**: Tall screen format


                        Default: 1:1
                      enum:
                        - '2:3'
                        - '3:2'
                        - '1:1'
                        - '16:9'
                        - '9:16'
                      example: '3:2'
                  required:
                    - prompt
              required:
                - model
                - input
              example:
                model: grok-imagine/text-to-image
                callBackUrl: https://your-domain.com/api/callback
                input:
                  prompt: >-
                    Cinematic portrait of a woman sitting by a vinyl record
                    player, retro living room background, soft ambient lighting,
                    warm earthy tones, nostalgic 1970s wardrobe, reflective
                    mood, gentle film grain texture, shallow depth of field,
                    vintage editorial photography style.
                  aspect_ratio: '3:2'
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
                            example: task_grok_12345678
              example:
                code: 200
                msg: success
                data:
                  taskId: task_grok_12345678
        '500':
          $ref: '#/components/responses/Error'
      callbacks:
        onImageGenerated:
          '{$request.body#/callBackUrl}':
            post:
              summary: Image Generation Callback
              description: >-
                When the image generation task is completed, the system will
                send the result to your provided callback URL via POST request
              requestBody:
                required: true
                content:
                  application/json:
                    schema:
                      type: object
                      properties:
                        code:
                          type: integer
                          description: >-
                            Status code


                            - **200**: Success - Image generation completed
                            successfully

                            - **501**: Failed - Image generation task failed
                          enum:
                            - 200
                            - 501
                        msg:
                          type: string
                          description: Status message
                          example: Playground task completed successfully.
                        data:
                          type: object
                          properties:
                            taskId:
                              type: string
                              description: Task ID
                              example: task_grok_12345678
                    example:
                      code: 200
                      msg: Playground task completed successfully.
                      data:
                        taskId: task_grok_12345678
              responses:
                '200':
                  description: Callback received successfully
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

            - **501**: Generation Failed - Image generation task failed

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