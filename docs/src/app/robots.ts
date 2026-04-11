import { generateRobots } from "onedocs/seo";

const baseUrl = "https://greph.dev";

export default function robots() {
  return generateRobots({ baseUrl });
}
